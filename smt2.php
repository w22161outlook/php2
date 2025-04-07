<?php
// 确保这是文件的绝对第一行，前面不能有任何空格或空行
ob_start(); // 开启输出缓冲

error_reporting(E_ALL);
ini_set('display_errors', 1);
date_default_timezone_set("Asia/Shanghai");

// 先设置header再启动session
header('Content-Type: text/plain; charset=utf-8');
session_start();

// 核心配置
const CONFIG = [
    'upstream'   => [
    'http://198.16.100.186:8278/',
    'http://50.7.92.106:8278/',  // 确保URL格式完整
    'http://50.7.234.10:8278/',
    'http://50.7.220.170:8278/',
    'http://67.159.6.34:8278/'
    ],
    'list_url'   => '/app/smart.txt',
    'token_ttl'  => 2400,  // 40分钟有效期
    'cache_ttl'  => 3600,  // 频道列表缓存1小时
    'fallback'   => 'http://vjs.zencdn.net/v/oceans.mp4', 
    'clear_key'  => 'gzluo',
    'backup_url' => '/app/smart.txt' 
];

// 获取当前轮询的上游服务器
function getUpstream() {
    static $index = 0;
    $upstreams = CONFIG['upstream'];
    $current = $upstreams[$index % count($upstreams)];
    $index++;
    return $current;
}

// 主路由控制
try {
    if (isset($_GET['action']) && $_GET['action'] === 'clear_cache') {
        clearCache();
    } elseif (!isset($_GET['id'])) {
        sendTXTList();
    } else {
        handleChannelRequest();
    }
} catch (Exception $e) {
    header('HTTP/1.1 503 Service Unavailable');
    exit("系统维护中，请稍后重试\n错误详情：" . $e->getMessage());
}

// 缓存清除
function clearCache() {
    error_log("[ClearCache] ClientIP:{$_SERVER['REMOTE_ADDR']}, Key:".($_GET['key']??'null'));

    $validKey = $_GET['key'] ?? '';
    $isLocal = in_array($_SERVER['REMOTE_ADDR'], ['127.0.0.1', '::1']);
    if (!$isLocal && !hash_equals(CONFIG['clear_key'], $validKey)) {
        header('HTTP/1.1 403 Forbidden');
        exit("权限验证失败\nIP: {$_SERVER['REMOTE_ADDR']}\n密钥状态: ".(empty($validKey)?'未提供':'无效'));
    }

    $results = [];
    $cacheType = '';

    if (extension_loaded('apcu')) {
        $cacheType = 'APCu';
        $results[] = apcu_clear_cache() ? '✅ APCu缓存已清除' : '❌ APCu清除失败';
    } else {
        $results[] = '⚠️ APCu扩展未安装';
    }

    try {
        $list = getChannelList(true);
        if (empty($list)) throw new Exception("频道列表为空");
        $results[] = '📡 频道列表已重建 数量:' . count($list);
        $cacheType = $cacheType ?: '无缓存扩展';
        $results[] = "🔧 使用缓存类型: $cacheType";
    } catch (Exception $e) {
        $results[] = '⚠️ 列表重建失败: ' . $e->getMessage();
    }

    $_SESSION = [];
    if (session_destroy()) {
        $results[] = '✅ Session已销毁';
    }

    header('Cache-Control: no-store');
    exit(implode("\n", $results));
}

// 生成M3U播放列表
function sendTXTList() {
    ob_start();
    header("Cache-Control: no-store, no-cache, must-revalidate");
    header("Pragma: no-cache");
    header("Expires: 0");
    header('Content-Type: audio/x-mpegurl');

    try {
        $channels = getChannelList();
        
        $output = "#EXTM3U\n";
        foreach ($channels as $chan) {
            // 生成EXTINF行
            $output .= sprintf('#EXTINF:-1 group-title="%s" tvg-id="%s",%s'."\n",
                htmlspecialchars($chan['group']),
                htmlspecialchars($chan['id']),
                htmlspecialchars($chan['name'])
            );
            
            // 生成播放地址行
            $output .= sprintf("Smart//:id=%s\n", 
                urlencode($chan['id'])
            );
        }

        header('Content-Disposition: inline; filename="playlist_'.time().'.m3u"');
        echo trim($output);
        
    } catch (Exception $e) {
        ob_clean();
        header('HTTP/1.1 500 Internal Server Error');
        exit("无法获取频道列表: " . $e->getMessage());
    }
    ob_end_flush();
}

// 获取频道列表（支持新旧格式）
function getChannelList($forceRefresh = false) {
    if (!$forceRefresh && extension_loaded('apcu')) {
        $cached = apcu_fetch('smart_channels');
        if ($cached !== false) {
            return $cached;
        }
    }

    $raw = fetchWithRetry(CONFIG['list_url'], 3);
    if ($raw === false) {
        $raw = fetchWithRetry(CONFIG['backup_url'], 2);
        if ($raw === false) {
            throw new Exception("所有数据源均不可用");
        }
    }

    $list = [];
    $currentGroup = '默认分组';
    $lines = explode("\n", trim($raw));
    $lineCount = count($lines);
    
    for ($i = 0; $i < $lineCount; $i++) {
        $line = trim($lines[$i]);
        if (empty($line)) continue;

        // 处理M3U格式
        if (strpos($line, '#EXTINF') === 0) {
            // 解析元数据
            $meta = [
                'group' => $currentGroup,
                'id' => '',
                'name' => ''
            ];
            
            // 提取group-title
            if (preg_match('/group-title="([^"]+)"/', $line, $matches)) {
                $meta['group'] = $matches[1];
            }
            
            // 提取tvg-id
            if (preg_match('/tvg-id="([^"]+)"/', $line, $matches)) {
                $meta['id'] = $matches[1];
            }
            
            // 提取频道名称（最后一个逗号后的内容）
            $nameStart = strrpos($line, ',');
            if ($nameStart !== false) {
                $meta['name'] = trim(substr($line, $nameStart + 1));
            }
            
            // 检查下一行是否是播放地址
            if ($i + 1 < $lineCount) {
                $nextLine = trim($lines[$i + 1]);
                if (strpos($nextLine, 'Smart//:id=') === 0) {
                    // 如果tvg-id为空，使用播放地址中的ID
                    if (empty($meta['id'])) {
                        $meta['id'] = substr($nextLine, strlen('Smart//:id='));
                    }
                    $i++; // 跳过下一行
                }
            }
            
            if (!empty($meta['id'])) {
                $list[] = [
                    'id' => $meta['id'],
                    'name' => $meta['name'],
                    'group' => $meta['group'],
                    'logo' => ''
                ];
            }
        }
        // 兼容旧格式：分组|频道名称,Smart//:id=频道ID
        elseif (strpos($line, '|') !== false && strpos($line, 'Smart//:id=') !== false) {
            $parts = explode(',', $line);
            $groupAndName = explode('|', $parts[0]);
            
            if (count($groupAndName) === 2) {
                $id = substr($parts[1], strpos($parts[1], 'Smart//:id=') + 11);
                $list[] = [
                    'id' => $id,
                    'name' => trim($groupAndName[1]),
                    'group' => trim($groupAndName[0]),
                    'logo' => ''
                ];
            }
        }
    }

    if (empty($list)) {
        throw new Exception("频道列表解析失败");
    }

    if (extension_loaded('apcu')) {
        apcu_store('smart_channels', $list, CONFIG['cache_ttl']);
    }

    return $list;
}
// 带重试机制的获取函数
function fetchWithRetry($url, $maxRetries = 3) {
    $retryDelay = 500; // 毫秒
    $lastError = '';
    
    for ($i = 0; $i < $maxRetries; $i++) {
        try {
            $ctx = stream_context_create([
                'http' => [
                    'timeout' => 5,
                    'header' => "User-Agent: Mozilla/5.0\r\n"
                ],
                'ssl' => [
                    'verify_peer' => false,
                    'verify_peer_name' => false
                ]
            ]);
            
            $raw = @file_get_contents($url, false, $ctx);
            if ($raw !== false) {
                return $raw;
            }
            $lastError = error_get_last()['message'] ?? '未知错误';
            
        } catch (Exception $e) {
            $lastError = $e->getMessage();
        }
        
        if ($i < $maxRetries - 1) {
            usleep($retryDelay * 1000);
            $retryDelay *= 2; // 指数退避
        }
    }
    
    error_log("[Fetch] 获取失败: $url, 错误: $lastError");
    return false;
}

// 处理频道请求
function handleChannelRequest() {
    $channelId = $_GET['id'];
    $tsFile    = $_GET['ts'] ?? '';
    $token     = manageToken();

    if ($tsFile) {
        proxyTS($channelId, $tsFile);
    } else {
        generateM3U8($channelId, $token);
    }
}

// Token管理
function manageToken() {
    $token = $_GET['token'] ?? '';
    
    if (empty($_SESSION['token']) || 
        !hash_equals($_SESSION['token'], $token) || 
        (time() - $_SESSION['token_time']) > CONFIG['token_ttl']) {
        
        $token = bin2hex(random_bytes(16));
        $_SESSION = [
            'token'      => $token,
            'token_time' => time()
        ];
        
        if (isset($_GET['ts'])) {
            $url = getBaseUrl() . '/' . basename(__FILE__) . '?' . http_build_query([
                'id'    => $_GET['id'],
                'ts'    => $_GET['ts'],
                'token' => $token
            ]);
            header("Location: $url");
            exit();
        }
    }
    
    return $token;
}

// 生成M3U8播放列表
function generateM3U8($channelId, $token) {
    $upstream = getUpstream();
    $authUrl = $upstream . "$channelId/playlist.m3u8?" . http_build_query([
        'tid'  => 'mc42afe745533',
        'ct'   => intval(time() / 150),
        'tsum' => md5("tvata nginx auth module/$channelId/playlist.m3u8mc42afe745533" . intval(time() / 150))
    ]);
    
    $content = fetchUrl($authUrl);
     if (empty($content) || strpos($content, "404 Not Found") !== false) {
        header("Location: http://vjs.zencdn.net/v/oceans.mp4");
        exit();
    }
    
    $baseUrl = getBaseUrl() . '/' . basename(__FILE__);
    $content = preg_replace_callback('/(\S+\.ts)/', function($m) use ($baseUrl, $channelId, $token) {
        return "$baseUrl?id=" . urlencode($channelId) . "&ts=" . urlencode($m[1]) . "&token=" . urlencode($token);
    }, $content);
    
    header('Content-Type: application/vnd.apple.mpegurl');
    echo $content;
}

// 代理TS流
function proxyTS($channelId, $tsFile) {
    $upstream = getUpstream();
    $url = $upstream . "$channelId/$tsFile";
    $data = fetchUrl($url);
    
    if ($data === null) {
        header('HTTP/1.1 404 Not Found');
        exit();
    }
    
    header('Content-Type: video/MP2T');
    header('Content-Length: ' . strlen($data));
    echo $data;
}

// 通用URL获取
function fetchUrl($url) {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => ["CLIENT-IP: 127.0.0.1", "X-FORWARDED-FOR: 127.0.0.1"],
        CURLOPT_TIMEOUT        => 5,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_FOLLOWLOCATION => true
    ]);
    
    $data = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    return $code == 200 ? $data : null;
}

// 获取基础URL
function getBaseUrl() {
    return (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http') 
           . "://$_SERVER[HTTP_HOST]";
}
