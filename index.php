<?php
/**
 * SKR v4.0 - PHPStudy 专用版
 * 单文件 | 首页 + 论坛 + 游戏 + ZJO | 零配置安装
 */

// ==================== 基础配置 ====================
define('DEBUG', true);
define('VERSION', '4.0');
define('ROOT_DIR', __DIR__);
define('CONFIG_FILE', ROOT_DIR . '/config.php');
define('DATA_DIR', ROOT_DIR . '/data');
define('DB_FILE', DATA_DIR . '/class74.db');
define('SITE_NAME', 'SKR');
define('CLASS_NAME', 'TGT');

if (DEBUG) {
    error_reporting(E_ALL);
    ini_set('display_errors', '1');
} else {
    error_reporting(0);
    ini_set('display_errors', '0');
}

session_start();
date_default_timezone_set('Asia/Shanghai');

// ==================== 数据库类 ====================
class DB {
    private static $instance = null;
    private $pdo = null;
    private $type = 'sqlite';
    
    public static function get() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    public function ready() {
        return $this->pdo !== null;
    }
    
    private function init() {
        if ($this->pdo !== null) return true;
        
        try {
            if (!file_exists(CONFIG_FILE)) {
                throw new Exception('配置文件不存在');
            }
            
            require CONFIG_FILE;
            
            if ($config['type'] == 'mysql') {
                $this->type = 'mysql';
                $dsn = "mysql:host={$config['host']};dbname={$config['dbname']};charset=utf8mb4";
                $this->pdo = new PDO($dsn, $config['user'], $config['pass'], [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
                ]);
            } else {
                $this->type = 'sqlite';
                if (!is_dir(DATA_DIR)) {
                    @mkdir(DATA_DIR, 0777, true);
                    if (!is_dir(DATA_DIR)) {
                        throw new Exception('无法创建 data 目录，请手动创建并赋予 777 权限');
                    }
                }
                
                $testFile = DATA_DIR . '/test.tmp';
                if (@file_put_contents($testFile, '1') === false) {
                    throw new Exception('data 目录没有写入权限。解决办法：右键 data 文件夹 → 属性 → 安全 → 添加 Everyone → 勾选完全控制');
                }
                @unlink($testFile);
                
                $this->pdo = new PDO('sqlite:' . DB_FILE);
                $this->pdo->exec('PRAGMA foreign_keys = ON');
                $this->pdo->exec('PRAGMA journal_mode = WAL');
            }
            
            return true;
        } catch (Exception $e) {
            if (DEBUG) error_log("DB Init Error: " . $e->getMessage());
            return false;
        }
    }
    
    public function query($sql, $params = []) {
        if (!$this->init()) throw new Exception("数据库未初始化");
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt;
    }
    
    public function fetch($sql, $params = []) {
        return $this->query($sql, $params)->fetch();
    }
    
    public function fetchAll($sql, $params = []) {
        return $this->query($sql, $params)->fetchAll();
    }
    
    public function lastId() {
        return $this->pdo->lastInsertId();
    }
    
    public function type() {
        return $this->type;
    }
}

// ==================== 工具函数 ====================
function h($str) {
    return htmlspecialchars($str ?? '', ENT_QUOTES, 'UTF-8');
}

function csrf() {
    if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(16));
    return $_SESSION['csrf'];
}

function checkCsrf($token) {
    return isset($_SESSION['csrf']) && hash_equals($_SESSION['csrf'], $token ?? '');
}

function redirect($url) {
    header("Location: $url");
    exit;
}

function isLogin() {
    return isset($_SESSION['user_id']);
}

function isAdmin() {
    return isLogin() && ($_SESSION['is_admin'] ?? 0) == 1;
}

function ago($t) {
    if (!$t) return '刚刚';
    $d = time() - strtotime($t);
    if ($d < 60) return '刚刚';
    if ($d < 3600) return floor($d/60).'分前';
    if ($d < 86400) return floor($d/3600).'小时前';
    return date('m-d', strtotime($t));
}

// ==================== 安装系统 ====================
function doInstall() {
    $step = intval($_GET['step'] ?? 1);
    $err = '';
    
    if ($_SERVER['REQUEST_METHOD'] == 'POST') {
        if ($step == 1) {
            $dbType = $_POST['db_type'] ?? 'sqlite';
            
            if ($dbType == 'sqlite') {
                if (!is_dir(DATA_DIR)) {
                    @mkdir(DATA_DIR, 0777, true);
                }
                
                if (!is_dir(DATA_DIR)) {
                    $err = '创建 data 文件夹失败。<br><b>手动解决：</b>在 ' . basename(ROOT_DIR) . ' 文件夹内新建 "data" 文件夹，右键属性 → 安全 → 编辑 → 添加 → Everyone → 勾选完全控制';
                } else {
                    $test = DATA_DIR . '/test.tmp';
                    if (@file_put_contents($test, '1')) {
                        unlink($test);
                        file_put_contents(CONFIG_FILE, '<?php $config=["type"=>"sqlite"];');
                        redirect('?a=install&step=2');
                    } else {
                        $err = 'data 文件夹没有写入权限。请按上述步骤设置 Everyone 权限。';
                    }
                }
            } else {
                try {
                    $host = $_POST['host'] ?? 'localhost';
                    $name = $_POST['dbname'] ?? 'class74';
                    $user = $_POST['user'] ?? 'root';
                    $pass = $_POST['pass'] ?? '';
                    
                    $pdo = new PDO("mysql:host=$host", $user, $pass);
                    $pdo->exec("CREATE DATABASE IF NOT EXISTS `$name` CHARACTER SET utf8mb4");
                    file_put_contents(CONFIG_FILE, '<?php $config=["type"=>"mysql","host"=>"' . $host . '","dbname"=>"' . $name . '","user"=>"' . $user . '","pass"=>"' . $pass . '"];');
                    redirect('?a=install&step=2');
                } catch (PDOException $e) {
                    $err = 'MySQL 连接失败: ' . $e->getMessage();
                }
            }
        } elseif ($step == 2) {
            try {
                initDB();
                redirect('?a=install&step=3');
            } catch (Exception $e) {
                $err = '建表失败: ' . $e->getMessage();
                @unlink(CONFIG_FILE);
            }
        } elseif ($step == 3) {
            $user = trim($_POST['user'] ?? '');
            $pass = $_POST['pass'] ?? '';
            $nick = trim($_POST['nick'] ?? '');
            
            if (strlen($user) < 3 || strlen($pass) < 6) {
                $err = '用户名≥3位，密码≥6位';
            } else {
                try {
                    $db = DB::get();
                    $db->query("INSERT INTO users (username,password,nickname,is_admin,created_at) VALUES (?,?,?,1,?)",
                        [$user, password_hash($pass, PASSWORD_DEFAULT), $nick, date('Y-m-d H:i:s')]);
                    redirect('?a=install&step=4');
                } catch (Exception $e) {
                    $err = '创建失败: ' . $e->getMessage();
                }
            }
        }
    }
    
    htmlHead('安装向导');
    echo '<div style="max-width:500px;margin:50px auto;background:#1a1a25;padding:30px;border-radius:16px;border:1px solid #333;">';
    echo '<h2 style="text-align:center;margin-bottom:30px;color:#b026ff;">⚡ 系统安装</h2>';
    
    echo '<div style="display:flex;justify-content:center;gap:20px;margin-bottom:30px;">';
    for ($i=1; $i<=4; $i++) {
        $c = $i == $step ? '#b026ff' : ($i < $step ? '#39ff14' : '#444');
        echo '<div style="width:35px;height:35px;border-radius:50%;background:'.$c.';display:flex;align-items:center;justify-content:center;color:#fff;font-weight:bold;">'.$i.'</div>';
    }
    echo '</div>';
    
    if ($err) echo '<div style="background:rgba(255,0,0,0.1);border:1px solid #f00;color:#f55;padding:12px;border-radius:8px;margin-bottom:20px;">'.$err.'</div>';
    
    if ($step == 1) {
        echo '<form method="post">';
        echo '<label style="display:block;margin-bottom:8px;color:#888;">数据库类型</label>';
        echo '<select name="db_type" id="dbtype" onchange="toggleDb()" style="width:100%;padding:10px;background:#222;border:1px solid #444;color:#fff;border-radius:8px;margin-bottom:20px;">';
        echo '<option value="sqlite">🚀 SQLite (推荐，零配置)</option>';
        echo '<option value="mysql">🐬 MySQL (需要提前建库)</option></select>';
        
        echo '<div id="mysqlbox" style="display:none;">';
        echo '<input type="text" name="host" placeholder="主机: localhost" style="width:100%;margin-bottom:10px;padding:10px;background:#222;border:1px solid #444;color:#fff;border-radius:8px;">';
        echo '<input type="text" name="dbname" placeholder="数据库名: class74" style="width:100%;margin-bottom:10px;padding:10px;background:#222;border:1px solid #444;color:#fff;border-radius:8px;">';
        echo '<input type="text" name="user" placeholder="用户名: root" style="width:100%;margin-bottom:10px;padding:10px;background:#222;border:1px solid #444;color:#fff;border-radius:8px;">';
        echo '<input type="password" name="pass" placeholder="密码" style="width:100%;margin-bottom:10px;padding:10px;background:#222;border:1px solid #444;color:#fff;border-radius:8px;">';
        echo '</div>';
        
        echo '<button type="submit" style="width:100%;padding:12px;background:linear-gradient(135deg,#b026ff,#7928ca);border:none;color:#fff;border-radius:8px;cursor:pointer;font-size:16px;">下一步</button>';
        echo '</form>';
        echo '<script>function toggleDb(){document.getElementById("mysqlbox").style.display=document.getElementById("dbtype").value=="mysql"?"block":"none";}</script>';
    } elseif ($step == 2) {
        echo '<p style="text-align:center;color:#aaa;margin-bottom:20px;">即将创建数据表...</p>';
        echo '<form method="post"><button type="submit" style="width:100%;padding:12px;background:#39ff14;border:none;color:#000;border-radius:8px;cursor:pointer;font-size:16px;font-weight:bold;">开始创建</button></form>';
    } elseif ($step == 3) {
        echo '<form method="post">';
        echo '<input type="text" name="user" placeholder="管理员账号 (建议学号)" required style="width:100%;margin-bottom:15px;padding:12px;background:#222;border:1px solid #444;color:#fff;border-radius:8px;">';
        echo '<input type="text" name="nick" placeholder="班级昵称 (如: 小明)" required style="width:100%;margin-bottom:15px;padding:12px;background:#222;border:1px solid #444;color:#fff;border-radius:8px;">';
        echo '<input type="password" name="pass" placeholder="密码 (至少6位)" required style="width:100%;margin-bottom:20px;padding:12px;background:#222;border:1px solid #444;color:#fff;border-radius:8px;">';
        echo '<button type="submit" style="width:100%;padding:12px;background:#39ff14;border:none;color:#000;border-radius:8px;cursor:pointer;font-size:16px;font-weight:bold;">完成安装</button>';
        echo '</form>';
    } elseif ($step == 4) {
        echo '<div style="text-align:center;padding:20px 0;">';
        echo '<div style="font-size:60px;margin-bottom:20px;">🎉</div>';
        echo '<h3 style="color:#39ff14;margin-bottom:20px;">安装成功！</h3>';
        echo '<a href="./" style="display:inline-block;padding:12px 30px;background:#b026ff;color:#fff;text-decoration:none;border-radius:8px;">进入平台</a>';
        echo '</div>';
    }
    
    echo '</div>';
    htmlFoot();
}

function initDB() {
    $db = DB::get();
    $isSql = $db->type() == 'sqlite';
    $ai = $isSql ? 'INTEGER PRIMARY KEY AUTOINCREMENT' : 'INT AUTO_INCREMENT PRIMARY KEY';
    $text = $isSql ? 'TEXT' : 'LONGTEXT';
    
    // 用户表
    $db->query("CREATE TABLE IF NOT EXISTS users (
        id $ai, username VARCHAR(50) UNIQUE, password VARCHAR(255),
        nickname VARCHAR(50), signature VARCHAR(200), avatar VARCHAR(255),
        is_admin TINYINT DEFAULT 0, created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )");
    
    // 论坛房间
    $db->query("CREATE TABLE IF NOT EXISTS rooms (
        id $ai, slug VARCHAR(50) UNIQUE, name VARCHAR(100),
        description VARCHAR(255), color VARCHAR(20), icon VARCHAR(10), sort_order INT DEFAULT 0
    )");
    
    // 帖子
    $db->query("CREATE TABLE IF NOT EXISTS posts (
        id $ai, user_id INT, room_id INT, title VARCHAR(200), content $text,
        excerpt VARCHAR(300), views INT DEFAULT 0, likes INT DEFAULT 0,
        is_anonymous TINYINT DEFAULT 0, is_top TINYINT DEFAULT 0,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )");
    
    // 评论
    $db->query("CREATE TABLE IF NOT EXISTS comments (
        id $ai, post_id INT, user_id INT, content $text,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )");
    
    // 公告表
    $db->query("CREATE TABLE IF NOT EXISTS notices (
        id $ai, title VARCHAR(200), content $text, is_important TINYINT DEFAULT 0,
        created_by INT, created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )");
    
    // 在线状态
    $db->query("CREATE TABLE IF NOT EXISTS online (
        user_id INT PRIMARY KEY, updated_at DATETIME
    )");
    
    // 游戏房间
    $db->query("CREATE TABLE IF NOT EXISTS games (
        id $ai, type VARCHAR(20), player1 INT, player2 INT,
        status VARCHAR(20) DEFAULT 'wait', data $text, created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )");
    
    // 游戏记录
    $db->query("CREATE TABLE IF NOT EXISTS scores (
        id $ai, user_id INT, game VARCHAR(20), score INT,
        vs_ai TINYINT DEFAULT 1, difficulty INT DEFAULT 1,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )");
    
    // 插入默认房间
    $rooms = [
        ['study','📚 学习交流','作业讨论、资料分享','#00d4ff','📚',1],
        ['chat','💬 闲聊灌水','日常吐槽、班级八卦','#ff00ff','💬',2],
        ['treehole','🌲 匿名树洞','匿名倾诉、秘密分享','#39ff14','🌲',3],
        ['notice','📢 班级公告','重要通知、活动安排','#ffd700','📢',4],
        ['game','🎮 游戏约战','开黑组队、游戏邀请','#ff6b6b','🎮',5]
    ];
    foreach ($rooms as $r) {
        try {
            $db->query("INSERT OR IGNORE INTO rooms (slug,name,description,color,icon,sort_order) VALUES (?,?,?,?,?,?)", $r);
        } catch(Exception $e){}
    }
    
    // 插入示例公告
    try {
        $db->query("INSERT OR IGNORE INTO notices (title,content,is_important) VALUES (?,?,1)", 
            ['欢迎来到SKR', '这里是班级专属的交流空间，包含论坛专区和游戏专区。请文明发言，共同维护良好的班级氛围！']);
    } catch(Exception $e){}
}

// ==================== 路由 ====================
$a = $_GET['a'] ?? 'home';

// 检查安装
if (!file_exists(CONFIG_FILE) && $a != 'install') {
    redirect('?a=install');
}

// 更新在线状态
if (isLogin() && file_exists(CONFIG_FILE)) {
    try {
        $db = DB::get();
        if ($db->ready()) {
            $db->query("INSERT INTO online (user_id,updated_at) VALUES (?,?) ON DUPLICATE KEY UPDATE updated_at=?",
                [$_SESSION['user_id'], date('Y-m-d H:i:s'), date('Y-m-d H:i:s')]);
        }
    } catch(Exception $e){}
}

switch ($a) {
    case 'install': doInstall(); break;
    case 'login': doLogin(); break;
    case 'reg': doReg(); break;
    case 'logout': session_destroy(); redirect('./'); break;
    
    case 'home': showHome(); break;
    
    case 'forum': doForum(); break;
    case 'post': viewPost(); break;
    case 'newpost': newPost(); break;
    
    case 'game': doGame(); break;
    case 'play': playGame(); break;
    
    case 'zjo': doZJO(); break;
    case 'rain': doRain(); break;
    case 'particles': doParticles(); break;
    case 'hypercube': playHypercube(); break;
    case 'notice': doNotice(); break;
    
    case 'api': doApi(); break;
    
    default: showHome();
}

// ==================== 首页 ====================
function showHome() {
    $db = DB::get();
    
    // 统计数据
    $stats = ['users'=>0,'posts'=>0,'today'=>0,'games'=>0];
    $notices = [];
    $latest = [];
    $online = 0;
    
    try {
        $stats['users'] = $db->fetch("SELECT COUNT(*) as c FROM users")['c'] ?? 0;
        $stats['posts'] = $db->fetch("SELECT COUNT(*) as c FROM posts")['c'] ?? 0;
        $stats['games'] = $db->fetch("SELECT COUNT(*) as c FROM scores")['c'] ?? 0;
        
        // 今日新帖
        $dateSql = $db->type() == 'sqlite' ? "date('now')" : "CURDATE()";
        $stats['today'] = $db->fetch("SELECT COUNT(*) as c FROM posts WHERE DATE(created_at) = $dateSql")['c'] ?? 0;
        
        // 公告
        $notices = $db->fetchAll("SELECT * FROM notices ORDER BY is_important DESC, id DESC LIMIT 3");
        
        // 最新帖子
        $latest = $db->fetchAll("SELECT p.*,r.name as rname,r.color,u.nickname FROM posts p 
            JOIN rooms r ON p.room_id=r.id LEFT JOIN users u ON p.user_id=u.id 
            ORDER BY p.id DESC LIMIT 5");
        
        // 在线人数
        $online = $db->fetch("SELECT COUNT(*) as c FROM online WHERE updated_at > datetime('now', '-5 minute')")['c'] ?? 0;
    } catch(Exception $e){}
    
    htmlHead();
    echo '<style>
        .hero{background:linear-gradient(135deg,#0f0f1a 0%,#1a0f2e 50%,#0f0f1a 100%);padding:60px 20px;text-align:center;position:relative;overflow:hidden;}
        .hero::before{content:"";position:absolute;top:0;left:0;right:0;bottom:0;background:radial-gradient(circle at 20% 50%,rgba(176,38,255,0.1) 0%,transparent 50%),radial-gradient(circle at 80% 50%,rgba(0,212,255,0.1) 0%,transparent 50%);pointer-events:none;}
        .hero-content{position:relative;z-index:1;}
        .class-badge{display:inline-block;padding:8px 20px;background:rgba(176,38,255,0.2);border:1px solid rgba(176,38,255,0.5);border-radius:20px;color:#b026ff;font-size:14px;margin-bottom:20px;}
        .hero h1{font-size:48px;margin-bottom:15px;background:linear-gradient(90deg,#b026ff,#00d4ff);-webkit-background-clip:text;-webkit-text-fill-color:transparent;}
        .hero p{color:#888;font-size:18px;}
        
        .quick-stats{display:flex;justify-content:center;gap:60px;margin-top:40px;}
        .stat-box{text-align:center;}
        .stat-num{font-size:36px;font-weight:bold;color:#fff;}
        .stat-num span{color:#00d4ff;}
        .stat-label{color:#666;font-size:14px;margin-top:5px;}
        
        .main-container{max-width:1200px;margin:0 auto;padding:40px 20px;display:grid;grid-template-columns:1fr 300px;gap:30px;}
        
        .left-section{}
        .section-title{font-size:20px;margin-bottom:20px;display:flex;align-items:center;gap:10px;}
        .section-title span{color:#b026ff;}
        
        .zone-cards{display:grid;grid-template-columns:repeat(3,1fr);gap:20px;margin-bottom:40px;}
        .zone-card{background:linear-gradient(135deg,#1a1a25,#252535);border:1px solid #333;border-radius:16px;padding:25px;transition:0.3s;cursor:pointer;text-decoration:none;color:#fff;display:block;position:relative;overflow:hidden;}
        .zone-card:hover{transform:translateY(-5px);border-color:#b026ff;box-shadow:0 20px 40px rgba(176,38,255,0.2);}
        .zone-card::before{content:"";position:absolute;top:0;left:0;width:100%;height:4px;background:linear-gradient(90deg,#b026ff,#00d4ff);transform:scaleX(0);transition:0.3s;}
        .zone-card:hover::before{transform:scaleX(1);}
        .zone-icon{font-size:40px;margin-bottom:15px;}
        .zone-name{font-size:18px;font-weight:bold;margin-bottom:8px;}
        .zone-desc{color:#888;font-size:13px;line-height:1.6;}
        .zone-arrow{position:absolute;right:20px;top:50%;transform:translateY(-50%);opacity:0;transition:0.3s;}
        .zone-card:hover .zone-arrow{opacity:1;right:15px;}
        
        .notice-box{background:#1a1a25;border:1px solid #333;border-radius:12px;padding:20px;margin-bottom:30px;}
        .notice-item{padding:15px 0;border-bottom:1px solid #222;}
        .notice-item:last-child{border-bottom:none;padding-bottom:0;}
        .notice-item:first-child{padding-top:0;}
        .notice-title{display:flex;align-items:center;gap:8px;margin-bottom:8px;}
        .notice-title .tag{padding:2px 8px;background:#ffd700;color:#000;font-size:11px;border-radius:4px;font-weight:bold;}
        .notice-title .tag.normal{background:#444;color:#aaa;}
        .notice-title a{color:#fff;font-weight:500;text-decoration:none;}
        .notice-title a:hover{color:#00d4ff;}
        .notice-time{color:#555;font-size:12px;}
        
        .post-list{background:#1a1a25;border:1px solid #333;border-radius:12px;overflow:hidden;}
        .post-item{display:flex;align-items:center;padding:15px 20px;border-bottom:1px solid #222;transition:0.2s;}
        .post-item:hover{background:#222;}
        .post-item:last-child{border-bottom:none;}
        .post-rank{width:30px;height:30px;background:#333;border-radius:8px;display:flex;align-items:center;justify-content:center;font-size:14px;font-weight:bold;margin-right:15px;}
        .post-rank.hot{background:linear-gradient(135deg,#ff6b6b,#ff00ff);color:#fff;}
        .post-info{flex:1;}
        .post-title{color:#fff;text-decoration:none;font-size:15px;display:block;margin-bottom:5px;}
        .post-title:hover{color:#00d4ff;}
        .post-meta{color:#666;font-size:12px;display:flex;gap:15px;}
        .post-meta span{display:flex;align-items:center;gap:4px;}
        
        .sidebar{}
        .side-box{background:#1a1a25;border:1px solid #333;border-radius:12px;padding:20px;margin-bottom:20px;}
        .side-title{font-size:16px;margin-bottom:15px;padding-bottom:10px;border-bottom:1px solid #333;display:flex;align-items:center;gap:8px;}
        .side-title span{color:#39ff14;}
        
        .user-card{text-align:center;padding:10px;}
        .user-avatar{width:80px;height:80px;border-radius:50%;background:linear-gradient(135deg,#b026ff,#00d4ff);display:flex;align-items:center;justify-content:center;font-size:32px;margin:0 auto 15px;color:#fff;}
        .user-name{font-size:18px;margin-bottom:5px;}
        .user-id{color:#666;font-size:13px;margin-bottom:15px;}
        .user-stats{display:flex;justify-content:center;gap:20px;margin-bottom:20px;}
        .user-stat{text-align:center;}
        .user-stat-num{font-size:20px;font-weight:bold;color:#00d4ff;}
        .user-stat-label{font-size:12px;color:#666;}
        
        .btn-full{display:block;width:100%;padding:10px;background:#b026ff;color:#fff;text-align:center;text-decoration:none;border-radius:8px;margin-bottom:10px;transition:0.3s;}
        .btn-full:hover{background:#7928ca;}
        .btn-outline{display:block;width:100%;padding:10px;background:transparent;border:1px solid #444;color:#888;text-align:center;text-decoration:none;border-radius:8px;transition:0.3s;}
        .btn-outline:hover{border-color:#00d4ff;color:#00d4ff;}
        
        .online-list{max-height:200px;overflow-y:auto;}
        .online-item{display:flex;align-items:center;padding:8px 0;border-bottom:1px solid #222;}
        .online-item:last-child{border-bottom:none;}
        .online-dot{width:8px;height:8px;background:#39ff14;border-radius:50%;margin-right:10px;animation:pulse 2s infinite;}
        @keyframes pulse{0%,100%{opacity:1;}50%{opacity:0.5;}}
        .online-name{flex:1;color:#ccc;}
        .online-status{color:#39ff14;font-size:12px;}
        
        .game-mini{padding:10px 0;border-bottom:1px solid #222;}
        .game-mini:last-child{border-bottom:none;}
        .game-mini-title{font-size:14px;margin-bottom:5px;}
        .game-mini-score{color:#ffd700;font-size:12px;}
        
        @media(max-width:900px){
            .main-container{grid-template-columns:1fr;}
            .zone-cards{grid-template-columns:1fr;}
            .quick-stats{gap:30px;}
        }
    </style>';
    
    echo '<div class="hero">';
    echo '<div class="hero-content">';
    echo '<div class="class-badge">'.CLASS_NAME.'</div>';
    echo '<h1>'.SITE_NAME.'</h1>';
    echo '<p>学习交流 · 休闲娱乐 · 班级互动</p>';
    echo '<div class="quick-stats">';
    echo '<div class="stat-box"><div class="stat-num">'.$stats['users'].'</div><div class="stat-label">班级成员</div></div>';
    echo '<div class="stat-box"><div class="stat-num"><span>'.$stats['today'].'</span></div><div class="stat-label">今日新帖</div></div>';
    echo '<div class="stat-box"><div class="stat-num">'.$stats['posts'].'</div><div class="stat-label">总帖子数</div></div>';
    echo '<div class="stat-box"><div class="stat-num" style="color:#39ff14;">'.$online.'</div><div class="stat-label">在线同学</div></div>';
    echo '</div></div></div>';
    
    echo '<div class="main-container">';
    echo '<div class="left-section">';
    
    // 快捷入口
    echo '<h3 class="section-title"><span>⚡</span> 快速入口</h3>';
    echo '<div class="zone-cards">';
    echo '<a href="?a=forum" class="zone-card">';
    echo '<div class="zone-icon">💬</div>';
    echo '<div class="zone-name">论坛专区</div>';
    echo '<div class="zone-desc">班级交流、学习讨论<br>匿名树洞、二手市场</div>';
    echo '<div class="zone-arrow">→</div>';
    echo '</a>';
    echo '<a href="?a=game" class="zone-card">';
    echo '<div class="zone-icon">🎮</div>';
    echo '<div class="zone-name">游戏专区</div>';
    echo '<div class="zone-desc">围棋、五子棋、井字棋<br>猜数字、人机对战</div>';
    echo '<div class="zone-arrow">→</div>';
    echo '</a>';
    echo '<a href="?a=zjo" class="zone-card">';
    echo '<div class="zone-icon">🌧️</div>';
    echo '<div class="zone-name">ZJO空间</div>';
    echo '<div class="zone-desc">代码雨效果、视觉特效<br>数字美学、沉浸体验</div>';
    echo '<div class="zone-arrow">→</div>';
    echo '</a>';
    echo '</div>';
    
    // 公告
    if (!empty($notices)) {
        echo '<h3 class="section-title"><span>📢</span> 班级公告</h3>';
        echo '<div class="notice-box">';
        foreach ($notices as $n) {
            echo '<div class="notice-item">';
            echo '<div class="notice-title">';
            echo $n['is_important'] ? '<span class="tag">重要</span>' : '<span class="tag normal">通知</span>';
            echo '<a href="?a=notice&id='.$n['id'].'">'.h($n['title']).'</a>';
            echo '</div>';
            echo '<div class="notice-time">'.ago($n['created_at']).'</div>';
            echo '</div>';
        }
        echo '</div>';
    }
    
    // 最新帖子
    echo '<h3 class="section-title"><span>🔥</span> 最新动态</h3>';
    echo '<div class="post-list">';
    foreach ($latest as $i=>$p) {
        $hot = $i < 3 ? 'hot' : '';
        echo '<div class="post-item">';
        echo '<div class="post-rank '.$hot.'">'.($i+1).'</div>';
        echo '<div class="post-info">';
        echo '<a href="?a=post&id='.$p['id'].'" class="post-title">'.h($p['title']).'</a>';
        echo '<div class="post-meta">';
        echo '<span style="color:'.$p['color'].'">'.$p['rname'].'</span>';
        echo '<span>👤 '.h($p['nickname'] ?? '匿名').'</span>';
        echo '<span>👁️ '.$p['views'].'</span>';
        echo '<span>💬 '.($p['likes'] ?? 0).'</span>';
        echo '</div></div></div>';
    }
    echo '</div>';
    
    echo '</div>'; // left-section
    
    // 侧边栏
    echo '<div class="sidebar">';
    
    // 用户信息或登录
    if (isLogin()) {
        echo '<div class="side-box">';
        echo '<div class="user-card">';
        echo '<div class="user-avatar">'.mb_substr($_SESSION['nickname'], 0, 1).'</div>';
        echo '<div class="user-name">'.h($_SESSION['nickname']).'</div>';
        echo '<div class="user-id">@'.$_SESSION['user_id'].'</div>';
        echo '<div class="user-stats">';
        try {
            $db = DB::get();
            $myPosts = $db->fetch("SELECT COUNT(*) as c FROM posts WHERE user_id=?", [$_SESSION['user_id']])['c'] ?? 0;
            $myScore = $db->fetch("SELECT SUM(score) as s FROM scores WHERE user_id=?", [$_SESSION['user_id']])['s'] ?? 0;
        } catch(Exception $e){ $myPosts = 0; $myScore = 0; }
        echo '<div class="user-stat"><div class="user-stat-num">'.$myPosts.'</div><div class="user-stat-label">帖子</div></div>';
        echo '<div class="user-stat"><div class="user-stat-num">'.$myScore.'</div><div class="user-stat-label">游戏分</div></div>';
        echo '</div>';
        echo '<a href="?a=newpost" class="btn-full">✨ 发布新帖</a>';
        echo '<a href="?a=game" class="btn-outline">🎮 去玩游戏</a>';
        echo '</div></div>';
    } else {
        echo '<div class="side-box">';
        echo '<div class="user-card">';
        echo '<div class="user-avatar" style="background:#333;">?</div>';
        echo '<div class="user-name">游客</div>';
        echo '<div class="user-id" style="margin-bottom:20px;">登录后享受完整功能</div>';
        echo '<a href="?a=login" class="btn-full">登录</a>';
        echo '<a href="?a=reg" class="btn-outline">注册账号</a>';
        echo '</div></div>';
    }
    
    // 在线列表
    try {
        $db = DB::get();
        $onlines = $db->fetchAll("SELECT u.nickname FROM online o JOIN users u ON o.user_id=u.id WHERE o.updated_at > datetime('now', '-5 minute') LIMIT 10");
    } catch(Exception $e){ $onlines = []; }
    
    echo '<div class="side-box">';
    echo '<div class="side-title"><span>●</span> 在线同学 ('.count($onlines).')</div>';
    echo '<div class="online-list">';
    if (empty($onlines)) {
        echo '<div style="color:#666;text-align:center;padding:20px;">暂无在线同学</div>';
    } else {
        foreach ($onlines as $o) {
            echo '<div class="online-item">';
            echo '<div class="online-dot"></div>';
            echo '<div class="online-name">'.h($o['nickname']).'</div>';
            echo '<div class="online-status">在线</div>';
            echo '</div>';
        }
    }
    echo '</div></div>';
    
    // 游戏排行 - 修复：只显示有分数的游戏
    try {
        $topScores = $db->fetchAll("SELECT u.nickname, s.score, s.game, s.created_at FROM scores s JOIN users u ON s.user_id=u.id WHERE s.score > 0 ORDER BY s.score DESC, s.created_at ASC LIMIT 5");
    } catch(Exception $e){ $topScores = []; }
    
    echo '<div class="side-box">';
    echo '<div class="side-title"><span>🏆</span> 游戏达人</div>';
    if (empty($topScores)) {
        echo '<div style="color:#666;text-align:center;padding:10px;">暂无游戏记录</div>';
    } else {
        foreach ($topScores as $s) {
            $gameName = [
                'tictactoe' => '井字棋',
                'guess' => '猜数字',
                'gomoku' => '五子棋',
                'go' => '围棋'
            ][$s['game']] ?? $s['game'];
            echo '<div class="game-mini">';
            echo '<div class="game-mini-title">'.h($s['nickname']).'</div>';
            echo '<div class="game-mini-score">'.$gameName.' · '.$s['score'].'分</div>';
            echo '</div>';
        }
    }
    echo '</div>';
    
    echo '</div>'; // sidebar
    echo '</div>'; // main-container
    
    htmlFoot();
}

// ==================== ZJO模块 ====================
function doZJO() {
    htmlHead('ZJO空间');
    echo '<style>
        .zjo-hero{text-align:center;padding:60px 20px;background:linear-gradient(135deg,#0f0f1a,#001a0f);}
        .zjo-hero h1{font-size:42px;margin-bottom:15px;}
        .zjo-hero h1 span{color:#39ff14;}
        .zjo-hero p{color:#888;}
        
        .zjo-container{max-width:1000px;margin:0 auto;padding:40px 20px;}
        .zjo-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(300px,1fr));gap:25px;}
        
        .zjo-card{background:linear-gradient(135deg,#1a1a25,#252535);border:1px solid #333;border-radius:20px;padding:35px;text-align:center;transition:0.3s;position:relative;overflow:hidden;}
        .zjo-card:hover{transform:translateY(-8px);border-color:#39ff14;box-shadow:0 25px 50px rgba(57,255,20,0.15);}
        .zjo-card::before{content:"";position:absolute;top:0;left:0;right:0;height:4px;background:linear-gradient(90deg,#39ff14,#00ff88);transform:scaleX(0);transition:0.3s;}
        .zjo-card:hover::before{transform:scaleX(1);}
        
        .z-icon{font-size:60px;margin-bottom:20px;}
        .z-title{font-size:22px;font-weight:bold;margin-bottom:12px;}
        .z-desc{color:#888;font-size:14px;margin-bottom:25px;line-height:1.6;}
        
        .z-btn{display:inline-block;padding:12px 30px;background:#222;border:1px solid #444;color:#fff;text-decoration:none;border-radius:25px;font-size:14px;transition:0.3s;}
        .z-btn:hover{background:#39ff14;border-color:#39ff14;color:#000;}
    </style>';
    
    echo '<div class="zjo-hero">';
    echo '<h1><span>🌧️</span> ZJO空间</h1>';
    echo '<p>数字美学 · 代码艺术 · 视觉特效</p>';
    echo '</div>';
    
    echo '<div class="zjo-container">';
    echo '<div class="zjo-grid">';
    
    // 去淋雨（绿色代码雨）
    echo '<div class="zjo-card">';
    echo '<div class="z-icon">🌧️</div>';
    echo '<div class="z-title">去淋雨（绿色）</div>';
    echo '<div class="z-desc">经典Matrix代码雨效果<br>绿色字符下落动画<br>全屏沉浸式体验</div>';
    echo '<a href="?a=rain" class="z-btn">开始体验</a>';
    echo '</div>';
    
    // 粒子星空（蓝色粒子连线）
    echo '<div class="zjo-card">';
    echo '<div class="z-icon">✨</div>';
    echo '<div class="z-title">粒子星空（蓝色）</div>';
    echo '<div class="z-desc">蓝色粒子连线动画<br>科技感动态效果<br>放松治愈视觉体验</div>';
    echo '<a href="?a=particles" class="z-btn">开始体验</a>';
    echo '</div>';

    // 四维超立方体
    echo '<div class="zjo-card">';
    echo '<div class="z-icon">🔷</div>';
    echo '<div class="z-title">四维超立方体</div>';
    echo '<div class="z-desc">4D超正方体可视化<br>四轴旋转投影到3D<br>数学几何美学探索</div>';
    echo '<a href="?a=hypercube" class="z-btn">开始探索</a>';
    echo '</div>';
    
    // 预留更多ZJO功能
    echo '<div class="zjo-card">';
    echo '<div class="z-icon">🔮</div>';
    echo '<div class="z-title">更多特效</div>';
    echo '<div class="z-desc">即将推出<br>敬请期待新的视觉效果<br>数字艺术创作空间</div>';
    echo '<a href="#" class="z-btn" style="opacity:0.5;cursor:not-allowed;">即将开放</a>';
    echo '</div>';
    
    echo '</div></div>';
    
    htmlFoot();
}

// ==================== 代码雨页面 ====================
function doRain() {
    echo '<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>下雨了 - ZJO空间</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        body {
            margin: 0;
            overflow: hidden;
            background: #000;
            font-family: "Microsoft YaHei","思源黑体", "微软雅黑","PingFang SC", "苹方", "黑体", Arial, sans-serif;
        }
        canvas {
            display: block;
        }
        .back-btn {
            position: fixed;
            top: 20px;
            left: 20px;
            z-index: 99;
            padding: 10px 20px;
            background: rgba(57, 255, 20, 0.2);
            border: 1px solid rgba(57, 255, 20, 0.5);
            color: #39ff14;
            text-decoration: none;
            border-radius: 8px;
            font-size: 14px;
            transition: all 0.3s;
            backdrop-filter: blur(10px);
        }
        .back-btn:hover {
            background: rgba(57, 255, 20, 0.4);
            transform: translateY(-2px);
        }
        .watermark {
            position: fixed;
            bottom: 10px;
            right: 10px;
            z-index: 99;
            color: rgba(255, 255, 255, 0.3);
            font-size: 20px;
            pointer-events: none;
            font-family: Arial, sans-serif;
        }
        .hint {
            position: fixed;
            bottom: 40px;
            left: 50%;
            transform: translateX(-50%);
            z-index: 99;
            color: rgba(57, 255, 20, 0.6);
            font-size: 12px;
            pointer-events: none;
            animation: fadeOut 3s forwards;
            animation-delay: 2s;
        }
        @keyframes fadeOut {
            to { opacity: 0; }
        }
    </style>
</head>
<body>
    <a href="?a=zjo" class="back-btn">← 返回ZJO空间</a>
    <div class="watermark">zjoweb.top</div>
    <div class="hint">按 F11 全屏体验最佳 · 点击返回按钮退出</div>
    <canvas id="rainCanvas"></canvas>
    
    <script>
        const canvas = document.getElementById("rainCanvas");
        const ctx = canvas.getContext("2d");
        
        function resize() {
            canvas.width = window.innerWidth;
            canvas.height = window.innerHeight;
        }
        resize();
        window.addEventListener("resize", resize);
        
        const fontSize = 14;
        const columns = Math.ceil(canvas.width / fontSize);
        const drops = [];
        for (let i = 0; i < columns; i++) {
            drops[i] = 1;
        }
        
        const chars = "ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789@#$%^&*()_+-=[]{}|;:,./<>?".split("");
        
        function draw() {
            ctx.fillStyle = "rgba(0, 0, 0, 0.05)";
            ctx.fillRect(0, 0, canvas.width, canvas.height);
            
            ctx.fillStyle = "#0F0";
            ctx.font = fontSize + "px monospace";
            
            for (let i = 0; i < drops.length; i++) {
                const text = chars[Math.floor(Math.random() * chars.length)];
                ctx.fillText(text, i * fontSize, drops[i] * fontSize);
                
                if (drops[i] * fontSize > canvas.height && Math.random() > 0.975) {
                    drops[i] = 0;
                }
                drops[i]++;
            }
        }
        
        setInterval(draw, 30);
    </script>
</body>
</html>';
    exit;
}

// ==================== 粒子背景页面 ====================
function doParticles() {
    echo '<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>粒子星空 - ZJO空间</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: "Microsoft YaHei","思源黑体", "微软雅黑","PingFang SC", "苹方", "黑体", Arial, sans-serif;
        }
        body {
            margin: 0;
            overflow: hidden;
            background: #090909;
            user-select: none;
        }
        #particle-canvas {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            z-index: -1;
            background: #090909;
        }
        .back-btn {
            position: fixed;
            top: 20px;
            left: 20px;
            z-index: 99;
            padding: 10px 20px;
            background: rgba(100, 220, 255, 0.2);
            border: 1px solid rgba(100, 220, 255, 0.5);
            color: #64dcff;
            text-decoration: none;
            border-radius: 8px;
            font-size: 14px;
            transition: all 0.3s;
            backdrop-filter: blur(10px);
        }
        .back-btn:hover {
            background: rgba(100, 220, 255, 0.4);
            transform: translateY(-2px);
        }
        .watermark {
            position: fixed;
            bottom: 10px;
            right: 10px;
            z-index: 99;
            color: rgba(255, 255, 255, 0.3);
            font-size: 20px;
            pointer-events: none;
        }
        .hint {
            position: fixed;
            bottom: 40px;
            left: 50%;
            transform: translateX(-50%);
            z-index: 99;
            color: rgba(100, 220, 255, 0.6);
            font-size: 12px;
            pointer-events: none;
            animation: fadeOut 3s forwards;
            animation-delay: 2s;
        }
        @keyframes fadeOut {
            to { opacity: 0; }
        }
        .stats {
            position: fixed;
            top: 20px;
            right: 20px;
            z-index: 99;
            color: rgba(100, 220, 255, 0.5);
            font-size: 12px;
            text-align: right;
        }
    </style>
</head>
<body>
    <a href="?a=zjo" class="back-btn">← 返回ZJO空间</a>
    <div class="watermark">zjoweb.top</div>
    <div class="hint">按 F11 全屏体验最佳 · 点击返回按钮退出</div>
    <div class="stats">
        <div>粒子数量: <span id="count">160</span></div>
        <div>FPS: <span id="fps">60</span></div>
    </div>
    <canvas id="particle-canvas"></canvas>
    
    <script>
        const canvas = document.getElementById("particle-canvas");
        const ctx = canvas.getContext("2d");
        
        function resize() {
            canvas.width = window.innerWidth;
            canvas.height = window.innerHeight;
        }
        resize();
        window.addEventListener("resize", resize);
        
        const particles = [];
        const particleCount = 160;
        
        class Particle {
            constructor() {
                this.x = Math.random() * canvas.width;
                this.y = Math.random() * canvas.height;
                this.size = Math.random() * 2 + 0.8;
                this.speedX = Math.random() * 1 - 0.5;
                this.speedY = Math.random() * 1 - 0.5;
                this.color = `rgba(100, 220, 255, ${Math.random() * 0.3 + 0.1})`;
            }
          
            update() {
                this.x += this.speedX;
                this.y += this.speedY;
                
                if (this.x > canvas.width) this.x = 0;
                if (this.x < 0) this.x = canvas.width;
                if (this.y > canvas.height) this.y = 0;
                if (this.y < 0) this.y = canvas.height;
            }
            
            draw() {
                ctx.fillStyle = this.color;
                ctx.beginPath();
                ctx.arc(this.x, this.y, this.size, 0, Math.PI * 2);
                ctx.fill();
            }
        }

        for (let i = 0; i < particleCount; i++) {
            particles.push(new Particle());
        }
        
        let lastTime = 0;
        let frameCount = 0;
        let fps = 60;
        
        function drawConnections() {
            for (let i = 0; i < particles.length; i++) {
                for (let j = i + 1; j < particles.length; j++) {
                    const dx = particles[i].x - particles[j].x;
                    const dy = particles[i].y - particles[j].y;
                    const distance = Math.sqrt(dx * dx + dy * dy);
                    
                    if (distance < 100) {
                        ctx.beginPath();
                        ctx.strokeStyle = `rgba(100, 220, 255, ${0.8 * (1 - distance/100)})`;
                        ctx.lineWidth = 0.5;
                        ctx.moveTo(particles[i].x, particles[i].y);
                        ctx.lineTo(particles[j].x, particles[j].y);
                        ctx.stroke();
                    }
                }
            }
        }

        function animate(currentTime) {
            ctx.clearRect(0, 0, canvas.width, canvas.height);
            
            particles.forEach(particle => {
                particle.update();
                particle.draw();
            });
            
            drawConnections();
            
            // 计算FPS
            frameCount++;
            if (currentTime - lastTime >= 1000) {
                fps = frameCount;
                frameCount = 0;
                lastTime = currentTime;
                document.getElementById("fps").textContent = fps;
            }
            
            requestAnimationFrame(animate);
        }
        
        animate(0);
    </script>
</body>
</html>';

    exit;
}

// ==================== 四维超立方体 ====================
function playHypercube() {
    echo '<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>四维超立方体 - ZJO空间</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: "Microsoft YaHei","思源黑体", "微软雅黑","PingFang SC", "苹方", "黑体", Arial, sans-serif;
        }
        
        body {
            background-color: #0a0a0f;
            color: #e0e0ff;
            line-height: 1.6;
            min-height: 100vh;
            overflow-x: hidden;
            user-select: none;
        }
        
        .container {
            max-width: 1400px;
            margin: 0 auto;
            padding: 20px;
        }
        
        header {
            text-align: center;
            margin-bottom: 30px;
            padding: 20px;
            background-color: rgba(176, 38, 255, 0.1);
            border-radius: 15px;
            border: 1px solid rgba(176, 38, 255, 0.3);
            box-shadow: 0 8px 32px rgba(176, 38, 255, 0.1);
        }
        
        h1 {
            font-size: 2.8rem;
            margin-bottom: 10px;
            background: linear-gradient(90deg, #b026ff 0%, #00d4ff 100%);
            -webkit-background-clip: text;
            background-clip: text;
            color: transparent;
        }
        
        .subtitle {
            font-size: 1.2rem;
            color: #888;
            max-width: 800px;
            margin: 0 auto;
        }
        
        .content {
            display: flex;
            flex-wrap: wrap;
            gap: 30px;
            margin-bottom: 30px;
        }
        
        .visualization-section {
            flex: 1;
            min-width: 300px;
            background-color: rgba(26, 26, 37, 0.8);
            border-radius: 15px;
            padding: 20px;
            border: 1px solid #333;
            box-shadow: 0 8px 32px rgba(0, 0, 0, 0.3);
        }
        
        .controls-section {
            flex: 0 0 350px;
            background-color: rgba(26, 26, 37, 0.8);
            border-radius: 15px;
            padding: 20px;
            border: 1px solid #333;
            box-shadow: 0 8px 32px rgba(0, 0, 0, 0.3);
        }
        
        h2 {
            font-size: 1.5rem;
            margin-bottom: 20px;
            color: #00d4ff;
            border-bottom: 2px solid rgba(0, 212, 255, 0.3);
            padding-bottom: 8px;
        }
        
        #hypercube-container {
            width: 100%;
            height: 500px;
            border-radius: 10px;
            overflow: hidden;
            background-color: #000;
            border: 1px solid #333;
        }
        
        .control-group {
            margin-bottom: 20px;
        }
        
        .control-label {
            display: flex;
            justify-content: space-between;
            margin-bottom: 8px;
            font-weight: 600;
            font-size: 14px;
            color: #ccc;
        }
        
        .slider-container {
            margin-bottom: 15px;
        }
        
        .slider-value {
            color: #00d4ff;
            font-weight: bold;
        }
        
        input[type="range"] {
            width: 100%;
            height: 8px;
            border-radius: 4px;
            background: rgba(255, 255, 255, 0.1);
            outline: none;
            -webkit-appearance: none;
        }
        
        input[type="range"]::-webkit-slider-thumb {
            -webkit-appearance: none;
            width: 18px;
            height: 18px;
            border-radius: 50%;
            background: #00d4ff;
            cursor: pointer;
            box-shadow: 0 0 10px rgba(0, 212, 255, 0.5);
        }
        
        .checkbox-group {
            display: flex;
            align-items: center;
            margin-bottom: 12px;
        }
        
        .checkbox-group label {
            margin-left: 10px;
            cursor: pointer;
            color: #ccc;
        }
        
        input[type="checkbox"] {
            width: 16px;
            height: 16px;
            cursor: pointer;
            accent-color: #b026ff;
        }
        
        .projection-info {
            background-color: rgba(176, 38, 255, 0.1);
            padding: 15px;
            border-radius: 10px;
            margin-top: 20px;
            font-size: 0.9rem;
            border: 1px solid rgba(176, 38, 255, 0.3);
        }
        
        .projection-info p {
            margin-bottom: 8px;
            color: #aaa;
        }
        
        .projection-info strong {
            color: #00d4ff;
        }
        
        .btn-reset {
            width: 100%;
            padding: 12px;
            background: linear-gradient(135deg, #b026ff, #7928ca);
            color: #fff;
            border: none;
            border-radius: 8px;
            font-weight: bold;
            cursor: pointer;
            font-size: 1rem;
            transition: 0.3s;
        }
        
        .btn-reset:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 20px rgba(176, 38, 255, 0.4);
        }
        
        .back-btn {
            position: fixed;
            top: 20px;
            left: 20px;
            z-index: 99;
            padding: 10px 20px;
            background: rgba(176, 38, 255, 0.2);
            border: 1px solid rgba(176, 38, 255, 0.5);
            color: #b026ff;
            text-decoration: none;
            border-radius: 8px;
            font-size: 14px;
            transition: all 0.3s;
            backdrop-filter: blur(10px);
        }
        
        .back-btn:hover {
            background: rgba(176, 38, 255, 0.4);
            transform: translateY(-2px);
        }
        
        .watermark {
            position: fixed;
            bottom: 10px;
            right: 10px;
            z-index: 99;
            color: rgba(255, 255, 255, 0.2);
            font-size: 20px;
            pointer-events: none;
        }
        
        @media (max-width: 768px) {
            .content {
                flex-direction: column;
            }
            .controls-section {
                flex: 1;
            }
            h1 {
                font-size: 2rem;
            }
            #hypercube-container {
                height: 400px;
            }
        }
    </style>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/three.js/r128/three.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/three@0.128.0/examples/js/controls/OrbitControls.min.js"></script>
</head>
<body>
    <a href="?a=zjo" class="back-btn">← 返回ZJO空间</a>
    <div class="watermark">zjoweb.top</div>
    <div class="container">
        <header>
            <h1>🔷 四维超立方体</h1>
            <p class="subtitle">可视化四维超立方。通过调整下面的控件，您可以旋转四维对象并观察其投影的变化。</p>
        </header>
        
        <div class="content">
            <section class="visualization-section">
                <h2>可视化</h2>
                <div id="hypercube-container"></div>
                <div class="projection-info">
                    <p><strong>当前投影类型：</strong>正交投影（4D → 3D）</p>
                    <p>四维超立方体有16个顶点、32条边和8个立方体胞。此可视化显示了将四维对象投影到我们三维空间中的效果。</p>
                </div>
            </section>
            
            <section class="controls-section">
                <h2>⚙️ 控制面板</h2>
                
                <div class="control-group">
                    <div class="control-label">
                        <span>XY平面旋转 (W轴)</span>
                        <span class="slider-value" id="w-rotation-value">0°</span>
                    </div>
                    <div class="slider-container">
                        <input type="range" id="w-rotation" min="0" max="360" value="0" step="1">
                    </div>
                </div>
                
                <div class="control-group">
                    <div class="control-label">
                        <span>XZ平面旋转</span>
                        <span class="slider-value" id="z-rotation-value">0°</span>
                    </div>
                    <div class="slider-container">
                        <input type="range" id="z-rotation" min="0" max="360" value="0" step="1">
                    </div>
                </div>
                
                <div class="control-group">
                    <div class="control-label">
                        <span>XW平面旋转</span>
                        <span class="slider-value" id="y-rotation-value">0°</span>
                    </div>
                    <div class="slider-container">
                        <input type="range" id="y-rotation" min="0" max="360" value="0" step="1">
                    </div>
                </div>
                
                <div class="control-group">
                    <div class="control-label">
                        <span>YW平面旋转</span>
                        <span class="slider-value" id="x-rotation-value">0°</span>
                    </div>
                    <div class="slider-container">
                        <input type="range" id="x-rotation" min="0" max="360" value="0" step="1">
                    </div>
                </div>
                
                <div class="control-group">
                    <div class="control-label">
                        <span>投影比例</span>
                        <span class="slider-value" id="scale-value">1.0</span>
                    </div>
                    <div class="slider-container">
                        <input type="range" id="scale" min="0.1" max="2" value="1" step="0.1">
                    </div>
                </div>
                
                <div class="control-group">
                    <h3 style="color:#b026ff;margin-bottom:15px;font-size:16px;">显示选项</h3>
                    <div class="checkbox-group">
                        <input type="checkbox" id="show-vertices" checked>
                        <label for="show-vertices">显示顶点</label>
                    </div>
                    
                    <div class="checkbox-group">
                        <input type="checkbox" id="show-edges" checked>
                        <label for="show-edges">显示边</label>
                    </div>
                    
                    <div class="checkbox-group">
                        <input type="checkbox" id="show-faces">
                        <label for="show-faces">显示面（透明）</label>
                    </div>
                    
                    <div class="checkbox-group">
                        <input type="checkbox" id="auto-rotate" checked>
                        <label for="auto-rotate">自动旋转（3D视图）</label>
                    </div>
                </div>
                
                <div class="control-group">
                    <button id="reset-view" class="btn-reset">
                        重置视图
                    </button>
                </div>
            </section>
        </div>
    </div>

    <script>
        // 主Three.js场景设置
        let scene, camera, renderer, controls;
        let hypercube = new THREE.Group();
        let vertices = [];
        let edges = [];
        let faces = [];
        
        // 4D超立方体顶点（4维坐标）
        const vertices4D = [];
        for (let i = 0; i < 16; i++) {
            const x = (i & 1) ? 1 : -1;
            const y = (i & 2) ? 1 : -1;
            const z = (i & 4) ? 1 : -1;
            const w = (i & 8) ? 1 : -1;
            vertices4D.push([x, y, z, w]);
        }
        
        // 超立方体边（连接顶点的索引对）
        const edges4D = [];
        for (let i = 0; i < 16; i++) {
            for (let j = i + 1; j < 16; j++) {
                let diff = 0;
                for (let k = 0; k < 4; k++) {
                    if (vertices4D[i][k] !== vertices4D[j][k]) diff++;
                }
                if (diff === 1) {
                    edges4D.push([i, j]);
                }
            }
        }
        
        // 超立方体面（8个立方体胞）
        const faces4D = [
            [0, 1, 3, 2, 4, 5, 7, 6],      // w = -1
            [8, 9, 11, 10, 12, 13, 15, 14], // w = 1
            [0, 1, 3, 2, 8, 9, 11, 10],     // z = -1
            [4, 5, 7, 6, 12, 13, 15, 14],   // z = 1
            [0, 1, 5, 4, 8, 9, 13, 12],     // y = -1
            [2, 3, 7, 6, 10, 11, 15, 14],   // y = 1
            [0, 2, 6, 4, 8, 10, 14, 12],    // x = -1
            [1, 3, 7, 5, 9, 11, 15, 13]     // x = 1
        ];
        
        function init() {
            const container = document.getElementById("hypercube-container");
            
            scene = new THREE.Scene();
            scene.background = new THREE.Color(0x000000);
            
            camera = new THREE.PerspectiveCamera(75, container.clientWidth / container.clientHeight, 0.1, 1000);
            camera.position.set(4, 3, 5);
            
            renderer = new THREE.WebGLRenderer({ antialias: true });
            renderer.setSize(container.clientWidth, container.clientHeight);
            container.appendChild(renderer.domElement);
            
            controls = new THREE.OrbitControls(camera, renderer.domElement);
            controls.enableDamping = true;
            controls.dampingFactor = 0.05;
            controls.autoRotate = true;
            controls.autoRotateSpeed = 1.0;
            
            const ambientLight = new THREE.AmbientLight(0xffffff, 0.6);
            scene.add(ambientLight);
            
            const directionalLight = new THREE.DirectionalLight(0xffffff, 0.8);
            directionalLight.position.set(5, 10, 7);
            scene.add(directionalLight);
            
            createHypercube();
            scene.add(hypercube);
            
            window.addEventListener("resize", onWindowResize);
            setupControls();
            animate();
        }
        
        function createHypercube() {
            hypercube = new THREE.Group();
            vertices = [];
            edges = [];
            faces = [];
            
            const projectedVertices = project4DTo3D(vertices4D, 0, 0, 0, 0, 1.0);
            
            // 创建顶点
            for (let i = 0; i < projectedVertices.length; i++) {
                const geometry = new THREE.SphereGeometry(0.06, 16, 16);
                const material = new THREE.MeshPhongMaterial({ 
                    color: 0x00d4ff,
                    emissive: 0x00d4ff,
                    emissiveIntensity: 0.5
                });
                const sphere = new THREE.Mesh(geometry, material);
                sphere.position.set(projectedVertices[i][0], projectedVertices[i][1], projectedVertices[i][2]);
                vertices.push(sphere);
                hypercube.add(sphere);
            }
            
            // 创建边
            for (let i = 0; i < edges4D.length; i++) {
                const [a, b] = edges4D[i];
                const geometry = new THREE.BufferGeometry().setFromPoints([
                    new THREE.Vector3(projectedVertices[a][0], projectedVertices[a][1], projectedVertices[a][2]),
                    new THREE.Vector3(projectedVertices[b][0], projectedVertices[b][1], projectedVertices[b][2])
                ]);
                const material = new THREE.LineBasicMaterial({ 
                    color: 0xb026ff,
                    linewidth: 2,
                    transparent: true,
                    opacity: 0.8
                });
                const line = new THREE.Line(geometry, material);
                edges.push(line);
                hypercube.add(line);
            }
            
            // 创建面
            for (let i = 0; i < faces4D.length; i++) {
                const faceVertices = faces4D[i];
                const points = [];
                for (let j = 0; j < faceVertices.length; j++) {
                    const vIndex = faceVertices[j];
                    points.push(new THREE.Vector3(
                        projectedVertices[vIndex][0],
                        projectedVertices[vIndex][1],
                        projectedVertices[vIndex][2]
                    ));
                }
                
                const geometry = new THREE.BufferGeometry();
                const positions = [];
                
                // 将立方体面分解为三角形
                const indices = [
                    [0, 1, 2], [0, 2, 3], // 前
                    [4, 5, 6], [4, 6, 7], // 后
                    [0, 1, 5], [0, 5, 4], // 底
                    [2, 3, 7], [2, 7, 6], // 顶
                    [0, 3, 7], [0, 7, 4], // 左
                    [1, 2, 6], [1, 6, 5]  // 右
                ];
                
                for (let idx of indices) {
                    for (let j of idx) {
                        positions.push(points[j].x, points[j].y, points[j].z);
                    }
                }
                
                geometry.setAttribute("position", new THREE.Float32BufferAttribute(positions, 3));
                
                const material = new THREE.MeshBasicMaterial({
                    color: 0xb026ff,
                    transparent: true,
                    opacity: 0.1,
                    side: THREE.DoubleSide
                });
                
                const faceMesh = new THREE.Mesh(geometry, material);
                faceMesh.visible = false;
                faces.push(faceMesh);
                hypercube.add(faceMesh);
            }
        }
        
        function project4DTo3D(points4D, rotX, rotY, rotZ, rotW, scale) {
            const result = [];
            const rx = THREE.MathUtils.degToRad(rotX);
            const ry = THREE.MathUtils.degToRad(rotY);
            const rz = THREE.MathUtils.degToRad(rotZ);
            const rw = THREE.MathUtils.degToRad(rotW);
            
            for (let i = 0; i < points4D.length; i++) {
                let [x, y, z, w] = points4D[i];
                
                // XY平面旋转 (W)
                let x1 = x * Math.cos(rw) - y * Math.sin(rw);
                let y1 = x * Math.sin(rw) + y * Math.cos(rw);
                let z1 = z;
                let w1 = w;
                
                // XZ平面旋转
                let x2 = x1 * Math.cos(rz) - z1 * Math.sin(rz);
                let y2 = y1;
                let z2 = x1 * Math.sin(rz) + z1 * Math.cos(rz);
                let w2 = w1;
                
                // XW平面旋转
                let x3 = x2 * Math.cos(ry) - w2 * Math.sin(ry);
                let y3 = y2;
                let z3 = z2;
                let w3 = x2 * Math.sin(ry) + w2 * Math.cos(ry);
                
                // YW平面旋转
                let x4 = x3;
                let y4 = y3 * Math.cos(rx) - w3 * Math.sin(rx);
                let z4 = z3;
                let w4 = y3 * Math.sin(rx) + w3 * Math.cos(rx);
                
                // 投影到3D空间
                const perspective = 2.0 / (2.0 + 0.3 * w4);
                x4 *= perspective * scale;
                y4 *= perspective * scale;
                z4 *= perspective * scale;
                
                result.push([x4, y4, z4]);
            }
            return result;
        }
        
        function updateHypercube() {
            const rotX = parseFloat(document.getElementById("x-rotation").value);
            const rotY = parseFloat(document.getElementById("y-rotation").value);
            const rotZ = parseFloat(document.getElementById("z-rotation").value);
            const rotW = parseFloat(document.getElementById("w-rotation").value);
            const scale = parseFloat(document.getElementById("scale").value);
            
            const projectedVertices = project4DTo3D(vertices4D, rotX, rotY, rotZ, rotW, scale);
            
            for (let i = 0; i < vertices.length; i++) {
                vertices[i].position.set(
                    projectedVertices[i][0],
                    projectedVertices[i][1],
                    projectedVertices[i][2]
                );
            }
            
            for (let i = 0; i < edges.length; i++) {
                const [a, b] = edges4D[i];
                const positions = edges[i].geometry.attributes.position.array;
                positions[0] = projectedVertices[a][0];
                positions[1] = projectedVertices[a][1];
                positions[2] = projectedVertices[a][2];
                positions[3] = projectedVertices[b][0];
                positions[4] = projectedVertices[b][1];
                positions[5] = projectedVertices[b][2];
                edges[i].geometry.attributes.position.needsUpdate = true;
            }
            
            for (let i = 0; i < faces.length; i++) {
                const faceVertices = faces4D[i];
                const positions = faces[i].geometry.attributes.position.array;
                let posIndex = 0;
                
                const indices = [
                    [0, 1, 2], [0, 2, 3],
                    [4, 5, 6], [4, 6, 7],
                    [0, 1, 5], [0, 5, 4],
                    [2, 3, 7], [2, 7, 6],
                    [0, 3, 7], [0, 7, 4],
                    [1, 2, 6], [1, 6, 5]
                ];
                
                for (let idx of indices) {
                    for (let j of idx) {
                        const vIndex = faceVertices[j];
                        positions[posIndex++] = projectedVertices[vIndex][0];
                        positions[posIndex++] = projectedVertices[vIndex][1];
                        positions[posIndex++] = projectedVertices[vIndex][2];
                    }
                }
                faces[i].geometry.attributes.position.needsUpdate = true;
            }
        }
        
        function setupControls() {
            const sliders = ["x-rotation", "y-rotation", "z-rotation", "w-rotation", "scale"];
            sliders.forEach(sliderId => {
                const slider = document.getElementById(sliderId);
                const valueDisplay = document.getElementById(sliderId + "-value");
                
                slider.addEventListener("input", function() {
                    if (sliderId === "scale") {
                        valueDisplay.textContent = parseFloat(this.value).toFixed(1);
                    } else {
                        valueDisplay.textContent = this.value + "°";
                    }
                    updateHypercube();
                });
            });
            
            const checkboxes = ["show-vertices", "show-edges", "show-faces", "auto-rotate"];
            checkboxes.forEach(checkboxId => {
                const checkbox = document.getElementById(checkboxId);
                checkbox.addEventListener("change", function() {
                    if (checkboxId === "show-vertices") {
                        vertices.forEach(v => v.visible = this.checked);
                    } else if (checkboxId === "show-edges") {
                        edges.forEach(e => e.visible = this.checked);
                    } else if (checkboxId === "show-faces") {
                        faces.forEach(f => f.visible = this.checked);
                    } else if (checkboxId === "auto-rotate") {
                        controls.autoRotate = this.checked;
                    }
                });
            });
            
            document.getElementById("reset-view").addEventListener("click", function() {
                document.getElementById("x-rotation").value = 0;
                document.getElementById("y-rotation").value = 0;
                document.getElementById("z-rotation").value = 0;
                document.getElementById("w-rotation").value = 0;
                document.getElementById("scale").value = 1.0;
                
                document.getElementById("x-rotation-value").textContent = "0°";
                document.getElementById("y-rotation-value").textContent = "0°";
                document.getElementById("z-rotation-value").textContent = "0°";
                document.getElementById("w-rotation-value").textContent = "0°";
                document.getElementById("scale-value").textContent = "1.0";
                
                controls.reset();
                updateHypercube();
            });
        }
        
        function onWindowResize() {
            const container = document.getElementById("hypercube-container");
            camera.aspect = container.clientWidth / container.clientHeight;
            camera.updateProjectionMatrix();
            renderer.setSize(container.clientWidth, container.clientHeight);
        }
        
        function animate() {
            requestAnimationFrame(animate);
            controls.update();
            renderer.render(scene, camera);
        }
        
        window.addEventListener("DOMContentLoaded", init);
    </script>
</body>
</html>';
    exit;
}
// ==================== 论坛功能 ====================
function doForum() {
    $db = DB::get();
    $act = $_GET['act'] ?? 'list';
    
    if ($act == 'list') {
        htmlHead('论坛');
        $rooms = $db->fetchAll("SELECT * FROM rooms ORDER BY sort_order");
        echo '<div style="max-width:1000px;margin:40px auto;padding:20px;">';
        echo '<h2 style="margin-bottom:30px;">📚 论坛专区</h2>';
        echo '<div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(280px,1fr));gap:20px;">';
        foreach ($rooms as $r) {
            $cnt = $db->fetch("SELECT COUNT(*) as c FROM posts WHERE room_id=?", [$r['id']])['c'] ?? 0;
            echo '<a href="?a=forum&act=room&id='.$r['id'].'" style="background:#1a1a25;border:1px solid #333;border-radius:16px;padding:25px;text-decoration:none;color:#fff;display:block;transition:0.3s;">';
            echo '<div style="font-size:45px;margin-bottom:15px;">'.$r['icon'].'</div>';
            echo '<h3 style="color:'.$r['color'].';font-size:18px;margin-bottom:8px;">'.h($r['name']).'</h3>';
            echo '<p style="color:#666;font-size:14px;margin-bottom:15px;">'.h($r['description']).'</p>';
            echo '<div style="display:flex;justify-content:space-between;align-items:center;">';
            echo '<span style="color:#888;font-size:12px;">'.$cnt.' 个话题</span>';
            echo '<span style="color:'.$r['color'].';">→</span>';
            echo '</div></a>';
        }
        echo '</div></div>';
        htmlFoot();
        
    } elseif ($act == 'room') {
        $rid = intval($_GET['id'] ?? 1);
        $room = $db->fetch("SELECT * FROM rooms WHERE id=?", [$rid]);
        if (!$room) redirect('?a=forum');
        
        $page = max(1, intval($_GET['p'] ?? 1));
        $per = 10;
        $off = ($page-1)*$per;
        
        $posts = $db->fetchAll("SELECT p.*,u.nickname FROM posts p LEFT JOIN users u ON p.user_id=u.id WHERE p.room_id=? ORDER BY p.is_top DESC, p.id DESC LIMIT $per OFFSET $off", [$rid]);
        
        htmlHead($room['name']);
        echo '<div style="background:linear-gradient(135deg,'.$room['color'].'20,transparent);padding:50px 20px;margin-bottom:30px;">';
        echo '<div style="max-width:1000px;margin:0 auto;">';
        echo '<div style="font-size:50px;margin-bottom:15px;">'.$room['icon'].'</div>';
        echo '<h1 style="color:'.$room['color'].';margin-bottom:10px;">'.h($room['name']).'</h1>';
        echo '<p style="color:#aaa;">'.h($room['description']).'</p></div></div>';
        
        echo '<div style="max-width:1000px;margin:0 auto;padding:0 20px;">';
        if (isLogin()) {
            echo '<a href="?a=newpost&rid='.$rid.'" style="display:inline-block;margin-bottom:25px;padding:12px 25px;background:'.$room['color'].';color:#000;text-decoration:none;border-radius:8px;font-weight:bold;">+ 发布话题</a>';
        }
        
        foreach ($posts as $p) {
            echo '<div style="background:#1a1a25;border:1px solid #333;border-radius:12px;padding:20px;margin-bottom:15px;transition:0.3s;">';
            echo '<div style="display:flex;justify-content:space-between;margin-bottom:12px;align-items:center;">';
            echo '<span style="color:'.($p['is_anonymous']?'#888':$room['color']).'">'.($p['is_anonymous']?'🎭 匿名':h($p['nickname'])).'</span>';
            echo '<span style="color:#555;font-size:12px;">'.ago($p['created_at']).'</span></div>';
            echo '<h3 style="margin-bottom:10px;"><a href="?a=post&id='.$p['id'].'" style="color:#fff;text-decoration:none;font-size:18px;">'.h($p['title']).'</a></h3>';
            echo '<p style="color:#888;font-size:14px;line-height:1.6;">'.h($p['excerpt']).'</p>';
            echo '<div style="margin-top:15px;display:flex;gap:20px;color:#666;font-size:13px;">';
            echo '<span>👁️ '.$p['views'].'</span>';
            echo '<span>❤️ '.$p['likes'].'</span>';
            echo '</div></div>';
        }
        echo '</div>';
        htmlFoot();
    }
}

function viewPost() {
    $id = intval($_GET['id'] ?? 0);
    $db = DB::get();
    $p = $db->fetch("SELECT p.*,r.color,r.name as rname FROM posts p JOIN rooms r ON p.room_id=r.id WHERE p.id=?", [$id]);
    if (!$p) redirect('?a=forum');
    
    $db->query("UPDATE posts SET views=views+1 WHERE id=?", [$id]);
    
    if ($_SERVER['REQUEST_METHOD']=='POST' && isLogin()) {
        if (checkCsrf($_POST['csrf']??'')) {
            $c = trim($_POST['content'] ?? '');
            if ($c) {
                $db->query("INSERT INTO comments (post_id,user_id,content) VALUES (?,?,?)", [$id,$_SESSION['user_id'],$c]);
                redirect("?a=post&id=$id");
            }
        }
    }
    
    $comments = $db->fetchAll("SELECT c.*,u.nickname FROM comments c JOIN users u ON c.user_id=u.id WHERE c.post_id=? ORDER BY c.id DESC", [$id]);
    
    htmlHead($p['title']);
    echo '<div style="max-width:800px;margin:40px auto;padding:20px;">';
    echo '<div style="color:'.$p['color'].';margin-bottom:15px;font-size:14px;">📂 '.h($p['rname']).'</div>';
    echo '<h1 style="margin-bottom:20px;line-height:1.4;">'.h($p['title']).'</h1>';
    echo '<div style="display:flex;justify-content:space-between;color:#666;font-size:14px;margin-bottom:30px;padding-bottom:20px;border-bottom:1px solid #333;">';
    echo '<span>'.ago($p['created_at']).'</span>';
    echo '<span>👁️ '.$p['views'].' 浏览</span>';
    echo '</div>';
    echo '<div style="line-height:1.8;font-size:16px;margin-bottom:40px;">'.nl2br(h($p['content'])).'</div>';
    
    // 评论
    echo '<h3 style="border-left:4px solid #b026ff;padding-left:15px;margin-bottom:25px;">评论 ('.count($comments).')</h3>';
    if (isLogin()) {
        echo '<form method="post" style="margin-bottom:30px;background:#1a1a25;padding:20px;border-radius:12px;">';
        echo '<input type="hidden" name="csrf" value="'.csrf().'">';
        echo '<textarea name="content" placeholder="写下你的评论..." style="width:100%;height:100px;padding:12px;background:#222;border:1px solid #444;color:#fff;border-radius:8px;margin-bottom:15px;resize:vertical;"></textarea>';
        echo '<button type="submit" style="padding:10px 25px;background:#b026ff;color:#fff;border:none;border-radius:6px;cursor:pointer;">发表评论</button>';
        echo '</form>';
    }
    
    foreach ($comments as $c) {
        echo '<div style="padding:20px 0;border-bottom:1px solid #222;">';
        echo '<div style="display:flex;justify-content:space-between;margin-bottom:10px;">';
        echo '<span style="color:#00d4ff;font-weight:500;">'.h($c['nickname']).'</span>';
        echo '<span style="color:#555;font-size:12px;">'.ago($c['created_at']).'</span></div>';
        echo '<div style="color:#ccc;line-height:1.6;">'.h($c['content']).'</div></div>';
    }
    echo '</div>';
    htmlFoot();
}

function newPost() {
    if (!isLogin()) redirect('?a=login');
    $rid = intval($_GET['rid'] ?? 1);
    $db = DB::get();
    
    if ($_SERVER['REQUEST_METHOD']=='POST') {
        if (checkCsrf($_POST['csrf']??'')) {
            $t = trim($_POST['title'] ?? '');
            $c = trim($_POST['content'] ?? '');
            if ($t && $c) {
                $ex = mb_substr(strip_tags($c),0,150);
                $db->query("INSERT INTO posts (user_id,room_id,title,content,excerpt) VALUES (?,?,?,?,?)",
                    [$_SESSION['user_id'],$rid,$t,$c,$ex]);
                redirect('?a=forum');
            }
        }
    }
    
    htmlHead('发布');
    echo '<div style="max-width:700px;margin:40px auto;padding:20px;">';
    echo '<h2 style="margin-bottom:25px;">✨ 发布新话题</h2>';
    echo '<form method="post">';
    echo '<input type="hidden" name="csrf" value="'.csrf().'">';
    echo '<input type="text" name="title" placeholder="标题" style="width:100%;padding:15px;background:#222;border:1px solid #444;color:#fff;border-radius:8px;margin-bottom:20px;font-size:16px;" required>';
    echo '<textarea name="content" placeholder="说点什么..." style="width:100%;height:250px;padding:15px;background:#222;border:1px solid #444;color:#fff;border-radius:8px;margin-bottom:20px;resize:vertical;font-size:15px;line-height:1.6;" required></textarea>';
    echo '<button type="submit" style="padding:12px 35px;background:#b026ff;color:#fff;border:none;border-radius:8px;cursor:pointer;font-size:16px;">发布</button>';
    echo '</form></div>';
    htmlFoot();
}

// ==================== 游戏功能 ====================
function doGame() {
    $db = DB::get();
    htmlHead('游戏');
    echo '<style>
        .game-hero{text-align:center;padding:60px 20px;background:linear-gradient(135deg,#0f0f1a,#1a0f2e);}
        .game-hero h1{font-size:42px;margin-bottom:15px;}
        .game-hero p{color:#888;}
        
        .game-container{max-width:1200px;margin:0 auto;padding:40px 20px;}
        .game-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(280px,1fr));gap:25px;}
        
        .game-card{background:linear-gradient(135deg,#1a1a25,#252535);border:1px solid #333;border-radius:20px;padding:30px;text-align:center;transition:0.3s;position:relative;overflow:hidden;}
        .game-card:hover{transform:translateY(-8px);border-color:#b026ff;box-shadow:0 25px 50px rgba(176,38,255,0.15);}
        .game-card::before{content:"";position:absolute;top:0;left:0;right:0;height:4px;background:linear-gradient(90deg,#b026ff,#00d4ff);transform:scaleX(0);transition:0.3s;}
        .game-card:hover::before{transform:scaleX(1);}
        
        .g-icon{font-size:50px;margin-bottom:15px;}
        .g-title{font-size:20px;font-weight:bold;margin-bottom:10px;}
        .g-desc{color:#888;font-size:13px;margin-bottom:20px;line-height:1.6;min-height:40px;}
        
        .diff-btns{display:flex;gap:8px;justify-content:center;flex-wrap:wrap;}
        .diff-btn{padding:8px 16px;background:#222;border:1px solid #444;color:#fff;text-decoration:none;border-radius:20px;font-size:13px;transition:0.3s;}
        .diff-btn:hover{background:#b026ff;border-color:#b026ff;}
        .diff-btn.primary{background:#b026ff;border-color:#b026ff;}
        .diff-btn.primary:hover{background:#7928ca;}
        .diff-btn.easy:hover{background:#39ff14;border-color:#39ff14;color:#000;}
        .diff-btn.hard:hover{background:#ff6b6b;border-color:#ff6b6b;}
        
        .rank-section{max-width:900px;margin:60px auto;padding:0 20px;}
        .rank-title{font-size:24px;margin-bottom:25px;text-align:center;}
        .rank-title span{color:#ffd700;}
        
        .rank-table{width:100%;background:#1a1a25;border-radius:12px;overflow:hidden;border:1px solid #333;}
        .rank-table th{background:#222;padding:15px;text-align:left;color:#888;font-weight:500;}
        .rank-table td{padding:15px;border-bottom:1px solid #222;}
        .rank-table tr:hover{background:#222;}
        .rank-num{width:40px;height:40px;background:#333;border-radius:50%;display:flex;align-items:center;justify-content:center;font-weight:bold;}
        .rank-num.top3{background:linear-gradient(135deg,#ffd700,#ffaa00);color:#000;}
        .score{color:#00d4ff;font-weight:bold;}
        
        .game-icon{width:60px;height:60px;margin:0 auto 15px;background:linear-gradient(135deg,#333,#444);border-radius:12px;display:flex;align-items:center;justify-content:center;font-size:30px;}
    </style>';
    
    echo '<div class="game-hero">';
    echo '<h1>🎮 游戏专区</h1>';
    echo '<p>棋类对弈 · 益智休闲 · 挑战自我</p>';
    echo '</div>';
    
    echo '<div class="game-container">';
    echo '<div class="game-grid">';
    
    // 围棋
    echo '<div class="game-card">';
    echo '<div class="game-icon">⚫</div>';
    echo '<div class="g-title">围棋</div>';
    echo '<div class="g-desc">古老智慧的结晶<br>19路棋盘，围地制胜</div>';
    echo '<div class="diff-btns">';
    echo '<a href="?a=play&g=go" class="diff-btn primary">开始对弈</a>';
    echo '</div></div>';
    
    // 五子棋
    echo '<div class="game-card">';
    echo '<div class="game-icon">⚫⚪</div>';
    echo '<div class="g-title">五子棋</div>';
    echo '<div class="g-desc">经典连珠游戏<br>五子连线即获胜</div>';
    echo '<div class="diff-btns">';
    echo '<a href="?a=play&g=gomoku" class="diff-btn primary">开始对弈</a>';
    echo '</div></div>';
    
    // 井字棋
    echo '<div class="game-card">';
    echo '<div class="game-icon">⭕</div>';
    echo '<div class="g-title">井字棋</div>';
    echo '<div class="g-desc">经典三子连线游戏<br>三种AI难度等你挑战</div>';
    echo '<div class="diff-btns">';
    echo '<a href="?a=play&g=tictactoe&lv=1" class="diff-btn easy">简单</a>';
    echo '<a href="?a=play&g=tictactoe&lv=2" class="diff-btn">中等</a>';
    echo '<a href="?a=play&g=tictactoe&lv=3" class="diff-btn hard">困难</a>';
    echo '</div></div>';
    
    // 猜数字
    echo '<div class="game-card">';
    echo '<div class="game-icon">🔢</div>';
    echo '<div class="g-title">猜数字</div>';
    echo '<div class="g-desc">猜1-100的神秘数字<br>考验你的逻辑推理</div>';
    echo '<div class="diff-btns">';
    echo '<a href="?a=play&g=guess" class="diff-btn primary">开始游戏</a>';
    echo '</div></div>';
    
    echo '</div></div>';
    
    // 排行榜 - 修复：按游戏类型分组显示
    echo '<div class="rank-section">';
    echo '<h2 class="rank-title"><span>🏆</span> 游戏排行榜</h2>';
    
    // 获取各游戏最高分
    $gameTypes = [
        'go' => '围棋',
        'gomoku' => '五子棋',
        'tictactoe' => '井字棋',
        'guess' => '猜数字'
    ];
    
    foreach ($gameTypes as $gameKey => $gameName) {
        echo '<h3 style="color:#888;font-size:16px;margin:30px 0 15px 0;padding-left:10px;border-left:3px solid #b026ff;">'.$gameName.'</h3>';
        echo '<table class="rank-table">';
        echo '<tr><th>排名</th><th>玩家</th><th>得分</th><th>时间</th></tr>';
        
        try {
            $scores = $db->fetchAll("SELECT s.*,u.nickname FROM scores s JOIN users u ON s.user_id=u.id WHERE s.game=? AND s.score > 0 ORDER BY s.score DESC, s.created_at ASC LIMIT 5", [$gameKey]);
            if (empty($scores)) {
                echo '<tr><td colspan="4" style="text-align:center;color:#666;">暂无记录</td></tr>';
            } else {
                foreach ($scores as $i=>$s) {
                    $rankClass = $i < 3 ? 'top3' : '';
                    $medal = $i + 1;
                    echo '<tr>';
                    echo '<td><div class="rank-num '.$rankClass.'">'.$medal.'</div></td>';
                    echo '<td>'.h($s['nickname']).'</td>';
                    echo '<td class="score">'.$s['score'].'</td>';
                    echo '<td style="color:#666;font-size:13px;">'.ago($s['created_at']).'</td>';
                    echo '</tr>';
                }
            }
        } catch(Exception $e){
            echo '<tr><td colspan="4" style="text-align:center;color:#666;">加载失败</td></tr>';
        }
        echo '</table>';
    }
    
    echo '</div>';
    
    htmlFoot();
}

function playGame() {
    $g = $_GET['g'] ?? '';
    switch($g) {
        case 'tictactoe':
            playTicTacToe();
            break;
        case 'guess':
            playGuess();
            break;
        case 'go':
            playGo();
            break;
        case 'gomoku':
            playGomoku();
            break;
        default:
            redirect('?a=game');
    }
}

// ==================== 围棋游戏 ====================
function playGo() {
    htmlHead('围棋');
    echo '<style>
        .game-wrap{max-width:900px;margin:30px auto;padding:20px;text-align:center;}
        .game-info{margin-bottom:20px;}
        .game-info h2{font-size:32px;margin-bottom:10px;}
        .game-subtitle{color:#888;font-size:14px;margin-bottom:20px;}
        
        #go-board-container{display:inline-block;position:relative;margin:20px auto;}
        #go-board{border:2px solid #d4a574;background:#dcb35c;box-shadow:0 10px 30px rgba(0,0,0,0.5);}
        
        .controls{margin:20px 0;display:flex;gap:15px;justify-content:center;align-items:center;flex-wrap:wrap;}
        .btn-game{padding:10px 25px;background:#333;color:#fff;border:none;border-radius:8px;cursor:pointer;font-size:14px;transition:0.3s;text-decoration:none;display:inline-block;}
        .btn-game:hover{background:#b026ff;}
        .btn-game.primary{background:#b026ff;}
        .btn-game.primary:hover{background:#7928ca;}
        .btn-game:disabled{opacity:0.5;cursor:not-allowed;}
        
        .game-info-panel{display:flex;justify-content:center;gap:40px;margin:20px 0;}
        .info-box{background:#1a1a25;padding:15px 25px;border-radius:12px;border:1px solid #333;}
        .info-label{color:#888;font-size:12px;margin-bottom:5px;}
        .info-value{color:#fff;font-size:20px;font-weight:bold;}
        .info-value.black{color:#333;text-shadow:0 0 5px #fff;}
        .info-value.white{color:#fff;}
        
        .status{margin:15px 0;font-size:18px;color:#ffd700;height:30px;}
        
        .rules{background:#1a1a25;border:1px solid #333;border-radius:12px;padding:20px;margin-top:30px;text-align:left;}
        .rules h3{color:#b026ff;margin-bottom:15px;}
        .rules p{color:#888;font-size:14px;line-height:1.8;margin-bottom:10px;}
    </style>';
    
    echo '<div class="game-wrap">';
    echo '<div class="game-info">';
    echo '<h2>⚫ 围棋 ⚪</h2>';
    echo '<div class="game-subtitle">黑先白后，轮流落子，围地多者胜</div>';
    echo '</div>';
    
    echo '<div class="status" id="game-status">黑棋回合</div>';
    
    echo '<div id="go-board-container">';
    echo '<canvas id="go-board" width="570" height="570"></canvas>';
    echo '</div>';
    
    echo '<div class="game-info-panel">';
    echo '<div class="info-box">';
    echo '<div class="info-label">⚫ 黑棋提子</div>';
    echo '<div class="info-value black" id="black-captured">0</div>';
    echo '</div>';
    echo '<div class="info-box">';
    echo '<div class="info-label">⚪ 白棋提子</div>';
    echo '<div class="info-value white" id="white-captured">0</div>';
    echo '</div>';
    echo '</div>';
    
    echo '<div class="controls">';
    echo '<button type="button" onclick="passTurn()" class="btn-game">停一手</button>';
    echo '<button type="button" onclick="undoMove()" class="btn-game">悔棋</button>';
    echo '<button type="button" onclick="resetGame()" class="btn-game">重新开始</button>';
    echo '<a href="?a=game" class="btn-game primary">返回大厅</a>';
    echo '</div>';
    
    echo '<div class="rules">';
    echo '<h3>📋 游戏规则</h3>';
    echo '<p>1. 黑棋先行，双方轮流在棋盘交叉点落子</p>';
    echo '<p>2. 棋子以直线相连形成整体，共享气（相邻空点）</p>';
    echo '<p>3. 无气的棋子被提走（吃掉）</p>';
    echo '<p>4. 禁止自杀（落子后己方无气，除非能提对方子）</p>';
    echo '<p>5. 双方连续停手则游戏结束，计算领地+提子数判定胜负</p>';
    echo '</div>';
    
    echo '</div>';
    
    echo '<script>
    (function(){
        const canvas = document.getElementById("go-board");
        const ctx = canvas.getContext("2d");
        const BOARD_SIZE = 19;
        const CELL_SIZE = 30;
        const MARGIN = 15;
        
        let board = [];
        let currentPlayer = "black";
        let blackCaptured = 0;
        let whiteCaptured = 0;
        let lastMove = null;
        let moveHistory = [];
        let gameEnded = false;
        
        function initBoard() {
            board = [];
            for(let i=0;i<BOARD_SIZE;i++) {
                board[i] = [];
                for(let j=0;j<BOARD_SIZE;j++) {
                    board[i][j] = null;
                }
            }
            currentPlayer = "black";
            blackCaptured = 0;
            whiteCaptured = 0;
            lastMove = null;
            moveHistory = [];
            gameEnded = false;
            updateStatus("黑棋回合");
            updateCaptureDisplay();
            drawBoard();
        }
        
        function drawBoard() {
            ctx.fillStyle = "#dcb35c";
            ctx.fillRect(0, 0, canvas.width, canvas.height);
            
            ctx.strokeStyle = "#000";
            ctx.lineWidth = 1;
            
            for(let i=0;i<BOARD_SIZE;i++) {
                ctx.beginPath();
                ctx.moveTo(MARGIN + i*CELL_SIZE, MARGIN);
                ctx.lineTo(MARGIN + i*CELL_SIZE, MARGIN + (BOARD_SIZE-1)*CELL_SIZE);
                ctx.stroke();
                
                ctx.beginPath();
                ctx.moveTo(MARGIN, MARGIN + i*CELL_SIZE);
                ctx.lineTo(MARGIN + (BOARD_SIZE-1)*CELL_SIZE, MARGIN + i*CELL_SIZE);
                ctx.stroke();
            }
            
            const stars = [[3,3],[3,9],[3,15],[9,3],[9,9],[9,15],[15,3],[15,9],[15,15]];
            stars.forEach(([x,y]) => {
                ctx.fillStyle = "#000";
                ctx.beginPath();
                ctx.arc(MARGIN + x*CELL_SIZE, MARGIN + y*CELL_SIZE, 4, 0, Math.PI*2);
                ctx.fill();
            });
            
            for(let i=0;i<BOARD_SIZE;i++) {
                for(let j=0;j<BOARD_SIZE;j++) {
                    if(board[i][j]) {
                        drawStone(i, j, board[i][j]);
                    }
                }
            }
            
            if(lastMove) {
                ctx.strokeStyle = "#f00";
                ctx.lineWidth = 2;
                ctx.beginPath();
                ctx.arc(MARGIN + lastMove.x*CELL_SIZE, MARGIN + lastMove.y*CELL_SIZE, 6, 0, Math.PI*2);
                ctx.stroke();
            }
        }
        
        function drawStone(x, y, color) {
            const cx = MARGIN + x*CELL_SIZE;
            const cy = MARGIN + y*CELL_SIZE;
            const radius = CELL_SIZE/2 - 1;
            
            const grad = ctx.createRadialGradient(cx-3, cy-3, 0, cx, cy, radius);
            if(color === "black") {
                grad.addColorStop(0, "#666");
                grad.addColorStop(1, "#000");
            } else {
                grad.addColorStop(0, "#fff");
                grad.addColorStop(1, "#ddd");
            }
            
            ctx.fillStyle = grad;
            ctx.beginPath();
            ctx.arc(cx, cy, radius, 0, Math.PI*2);
            ctx.fill();
        }
        
        function get liberties(x, y, color, visited = new Set()) {
            const key = x+","+y;
            if(visited.has(key)) return 0;
            visited.add(key);
            
            let liberties = 0;
            const dirs = [[0,1],[0,-1],[1,0],[-1,0]];
            
            for(let [dx,dy] of dirs) {
                const nx = x+dx, ny = y+dy;
                if(nx<0||nx>=BOARD_SIZE||ny<0||ny>=BOARD_SIZE) continue;
                
                if(board[nx][ny] === null) {
                    liberties++;
                } else if(board[nx][ny] === color) {
                    liberties += getLiberties(nx, ny, color, visited);
                }
            }
            return liberties;
        }
        
        function getGroup(x, y, color, group = []) {
            const key = x+","+y;
            if(group.some(p => p.x===x && p.y===y)) return group;
            
            group.push({x,y});
            const dirs = [[0,1],[0,-1],[1,0],[-1,0]];
            
            for(let [dx,dy] of dirs) {
                const nx = x+dx, ny = y+dy;
                if(nx>=0&&nx<BOARD_SIZE&&ny>=0&&ny<BOARD_SIZE&&board[nx][ny]===color) {
                    getGroup(nx, ny, color, group);
                }
            }
            return group;
        }
        
        function removeGroup(group) {
            group.forEach(p => {
                board[p.x][p.y] = null;
            });
        }
        
        function isValidMove(x, y) {
            if(x < 0 || x >= BOARD_SIZE || y < 0 || y >= BOARD_SIZE) return false;
            if(board[x][y] !== null) return false;
            
            // 检查是否是自杀手
            board[x][y] = currentPlayer;
            
            // 检查是否提子
            let captures = [];
            const dirs = [[0,1],[0,-1],[1,0],[-1,0]];
            for(let [dx,dy] of dirs) {
                const nx = x+dx, ny = y+dy;
                if(nx>=0 && nx<BOARD_SIZE && ny>=0 && ny<BOARD_SIZE && board[nx][ny] && board[nx][ny]!==currentPlayer) {
                    const visited = new Set();
                    const libs = getLiberties(nx, ny, board[nx][ny], visited);
                    if(libs === 0) {
                        captures.push({x:nx, y:ny});
                    }
                }
            }
            
            // 如果没有提子，检查自己的气
            if(captures.length === 0) {
                const visited = new Set();
                const libs = getLiberties(x, y, currentPlayer, visited);
                if(libs === 0) {
                    board[x][y] = null; // 恢复
                    return false; // 自杀手
                }
            }
            
            board[x][y] = null; // 恢复
            return true;
        }
        
        function makeMove(x, y) {
            if(gameEnded || !isValidMove(x, y)) return;
            
            moveHistory.push(JSON.parse(JSON.stringify(board)));
            
            board[x][y] = currentPlayer;
            lastMove = {x, y};
            
            let captures = [];
            const dirs = [[0,1],[0,-1],[1,0],[-1,0]];
            for(let [dx,dy] of dirs) {
                const nx = x+dx, ny = y+dy;
                if(nx>=0&&nx<BOARD_SIZE&&ny>=0&&ny<BOARD_SIZE&&board[nx][ny]&&board[nx][ny]!==currentPlayer) {
                    const oppLibs = getLiberties(nx, ny, board[nx][ny]);
                    if(oppLibs === 0) {
                        const group = getGroup(nx, ny, board[nx][ny]);
                        captures = captures.concat(group);
                        removeGroup(group);
                    }
                }
            }
            
            if(currentPlayer === "black") {
                blackCaptured += captures.length;
            } else {
                whiteCaptured += captures.length;
            }
            
            updateCaptureDisplay();
            saveScore(currentPlayer, captures.length);
            
            currentPlayer = currentPlayer === "black" ? "white" : "black";
            updateStatus(currentPlayer === "black" ? "黑棋回合" : "白棋回合");
            
            drawBoard();
        }
        
        function updateStatus(msg) {
            document.getElementById("game-status").textContent = msg;
        }
        
        function updateCaptureDisplay() {
            document.getElementById("black-captured").textContent = blackCaptured;
            document.getElementById("white-captured").textContent = whiteCaptured;
        }
        
        function passTurn() {
            if(gameEnded) return;
            
            moveHistory.push(JSON.parse(JSON.stringify(board)));
            currentPlayer = currentPlayer === "black" ? "white" : "black";
            updateStatus(currentPlayer === "black" ? "黑棋回合 (对方停一手)" : "白棋回合 (对方停一手)");
            
            if(moveHistory.length >= 2) {
                const last = moveHistory[moveHistory.length-1];
                const secondLast = moveHistory[moveHistory.length-2];
                if(JSON.stringify(last) === JSON.stringify(secondLast)) {
                    endGame();
                }
            }
        }
        
        function undoMove() {
            if(moveHistory.length === 0) return;
            board = moveHistory.pop();
            currentPlayer = currentPlayer === "black" ? "white" : "black";
            updateStatus(currentPlayer === "black" ? "黑棋回合" : "白棋回合");
            drawBoard();
        }
        
        function endGame() {
            gameEnded = true;
            
            let blackArea = 0, whiteArea = 0;
            let visited = new Set();
            
            for(let i=0;i<BOARD_SIZE;i++) {
                for(let j=0;j<BOARD_SIZE;j++) {
                    const key = i+","+j;
                    if(visited.has(key)) continue;
                    
                    if(board[i][j] === "black") {
                        blackArea++;
                        visited.add(key);
                    } else if(board[i][j] === "white") {
                        whiteArea++;
                        visited.add(key);
                    } else if(board[i][j] === null) {
                        const territory = getTerritory(i, j, visited);
                        if(territory.owner === "black") blackArea += territory.count;
                        else if(territory.owner === "white") whiteArea += territory.count;
                    }
                }
            }
            
            const blackScore = blackArea + blackCaptured;
            const whiteScore = whiteArea + whiteCaptured + 6.5;
            
            const winner = blackScore > whiteScore ? "黑棋" : "白棋";
            updateStatus(`游戏结束！${winner}胜 (黑:${blackScore.toFixed(1)} 白:${whiteScore.toFixed(1)})`);
            
            alert(`游戏结束！\\n黑棋: ${blackScore.toFixed(1)} (领地${blackArea} + 提子${blackCaptured})\\n白棋: ${whiteScore.toFixed(1)} (领地${whiteArea} + 提子${whiteCaptured} + 贴目6.5)\\n${winner}获胜！`);
        }
        
        function getTerritory(x, y, globalVisited) {
            let visited = new Set();
            let queue = [{x,y}];
            let territory = [];
            let borders = new Set();
            
            while(queue.length > 0) {
                const {x:cx,y:cy} = queue.shift();
                const key = cx+","+cy;
                
                if(visited.has(key)) continue;
                visited.add(key);
                globalVisited.add(key);
                territory.push({x:cx,y:cy});
                
                const dirs = [[0,1],[0,-1],[1,0],[-1,0]];
                for(let [dx,dy] of dirs) {
                    const nx = cx+dx, ny = cy+dy;
                    if(nx<0||nx>=BOARD_SIZE||ny<0||ny>=BOARD_SIZE) continue;
                    
                    const nkey = nx+","+ny;
                    if(board[nx][ny] === null) {
                        if(!visited.has(nkey)) queue.push({x:nx,y:ny});
                    } else {
                        borders.add(board[nx][ny]);
                    }
                }
            }
            
            let owner = null;
            if(borders.size === 1) {
                owner = Array.from(borders)[0];
            }
            
            return {owner, count: territory.length};
        }
        
        function saveScore(winner, captures) {
            if(captures > 0) {
                const score = captures * 10;
                fetch("?a=api&do=save&game=go&score=" + score + "&csrf='.csrf().'").catch(()=>{});
            }
        }
        
        canvas.addEventListener("click", (e) => {
            const rect = canvas.getBoundingClientRect();
            const x = Math.round((e.clientX - rect.left - MARGIN) / CELL_SIZE);
            const y = Math.round((e.clientY - rect.top - MARGIN) / CELL_SIZE);
            
            if(x>=0&&x<BOARD_SIZE&&y>=0&&y<BOARD_SIZE) {
                makeMove(x, y);
            }
        });
        
        window.resetGame = initBoard;
        window.passTurn = passTurn;
        window.undoMove = undoMove;
        
        initBoard();
    })();
    </script>';
    htmlFoot();
}

// ==================== 五子棋游戏 ====================
function playGomoku() {
    htmlHead('五子棋');
    echo '<style>
        .game-wrap{max-width:800px;margin:30px auto;padding:20px;text-align:center;}
        .game-info{margin-bottom:20px;}
        .game-info h2{font-size:32px;margin-bottom:10px;}
        .game-subtitle{color:#888;font-size:14px;margin-bottom:20px;}
        
        #gomoku-board-container{display:inline-block;position:relative;margin:20px auto;}
        #gomoku-board{border:2px solid #8b4513;background:#deb887;box-shadow:0 10px 30px rgba(0,0,0,0.5);}
        
        .controls{margin:20px 0;display:flex;gap:15px;justify-content:center;align-items:center;flex-wrap:wrap;}
        .btn-game{padding:10px 25px;background:#333;color:#fff;border:none;border-radius:8px;cursor:pointer;font-size:14px;transition:0.3s;text-decoration:none;display:inline-block;}
        .btn-game:hover{background:#b026ff;}
        .btn-game.primary{background:#b026ff;}
        .btn-game.primary:hover{background:#7928ca;}
        
        .status{margin:15px 0;font-size:20px;color:#ffd700;height:30px;}
        
        .game-mode{display:flex;gap:10px;justify-content:center;margin-bottom:20px;}
        .mode-btn{padding:8px 20px;background:#222;border:1px solid #444;color:#fff;border-radius:20px;cursor:pointer;transition:0.3s;}
        .mode-btn.active{background:#b026ff;border-color:#b026ff;}
        
        .rules{background:#1a1a25;border:1px solid #333;border-radius:12px;padding:20px;margin-top:30px;text-align:left;}
        .rules h3{color:#b026ff;margin-bottom:15px;}
        .rules p{color:#888;font-size:14px;line-height:1.8;}
    </style>';
    
    echo '<div class="game-wrap">';
    echo '<div class="game-info">';
    echo '<h2>⚫ 五子棋 ⚪</h2>';
    echo '<div class="game-subtitle">五子连线即获胜，横竖斜均可</div>';
    echo '</div>';
    
    echo '<div class="game-mode">';
    echo '<button type="button" class="mode-btn active" onclick="setMode(0)" id="mode-pvp">双人对战</button>';
    echo '<button type="button" class="mode-btn" onclick="setMode(1)" id="mode-pve">人机对战</button>';
    echo '</div>';
    
    echo '<div class="status" id="game-status">黑棋回合</div>';
    
    echo '<div id="gomoku-board-container">';
    echo '<canvas id="gomoku-board" width="450" height="450"></canvas>';
    echo '</div>';
    
    echo '<div class="controls">';
    echo '<button type="button" onclick="undoMove()" class="btn-game">悔棋</button>';
    echo '<button type="button" onclick="resetGame()" class="btn-game">重新开始</button>';
    echo '<a href="?a=game" class="btn-game primary">返回大厅</a>';
    echo '</div>';
    
    echo '<div class="rules">';
    echo '<h3>📋 游戏规则</h3>';
    echo '<p>1. 黑棋先行，双方轮流在棋盘交叉点落子</p>';
    echo '<p>2. 先形成五子连线（横、竖、斜）者获胜</p>';
    echo '<p>3. 六子及以上不算获胜（避免长连）</p>';
    echo '<p>4. 棋盘为15×15路</p>';
    echo '</div>';
    
    echo '</div>';
    
    echo '<script>
    (function(){
        const canvas = document.getElementById("gomoku-board");
        const ctx = canvas.getContext("2d");
        const BOARD_SIZE = 15;
        const CELL_SIZE = 30;
        const MARGIN = 15;
        
        let board = [];
        let currentPlayer = "black";
        let gameOver = false;
        let moveHistory = [];
        let gameMode = 0;
        let aiThinking = false;
        
        function initBoard() {
            board = [];
            for(let i=0;i<BOARD_SIZE;i++) {
                board[i] = [];
                for(let j=0;j<BOARD_SIZE;j++) {
                    board[i][j] = null;
                }
            }
            currentPlayer = "black";
            gameOver = false;
            moveHistory = [];
            aiThinking = false;
            updateStatus("黑棋回合");
            drawBoard();
        }
        
        function drawBoard() {
            ctx.fillStyle = "#deb887";
            ctx.fillRect(0, 0, canvas.width, canvas.height);
            
            ctx.strokeStyle = "#8b4513";
            ctx.lineWidth = 1;
            
            for(let i=0;i<BOARD_SIZE;i++) {
                ctx.beginPath();
                ctx.moveTo(MARGIN + i*CELL_SIZE, MARGIN);
                ctx.lineTo(MARGIN + i*CELL_SIZE, MARGIN + (BOARD_SIZE-1)*CELL_SIZE);
                ctx.stroke();
                
                ctx.beginPath();
                ctx.moveTo(MARGIN, MARGIN + i*CELL_SIZE);
                ctx.lineTo(MARGIN + (BOARD_SIZE-1)*CELL_SIZE, MARGIN + i*CELL_SIZE);
                ctx.stroke();
            }
            
            const stars = [[3,3],[3,11],[7,7],[11,3],[11,11]];
            stars.forEach(([x,y]) => {
                ctx.fillStyle = "#8b4513";
                ctx.beginPath();
                ctx.arc(MARGIN + x*CELL_SIZE, MARGIN + y*CELL_SIZE, 4, 0, Math.PI*2);
                ctx.fill();
            });
            
            for(let i=0;i<BOARD_SIZE;i++) {
                for(let j=0;j<BOARD_SIZE;j++) {
                    if(board[i][j]) {
                        drawStone(i, j, board[i][j]);
                    }
                }
            }
            
            if(moveHistory.length > 0) {
                const last = moveHistory[moveHistory.length-1];
                ctx.strokeStyle = "#f00";
                ctx.lineWidth = 2;
                ctx.beginPath();
                ctx.arc(MARGIN + last.x*CELL_SIZE, MARGIN + last.y*CELL_SIZE, 6, 0, Math.PI*2);
                ctx.stroke();
            }
        }
        
        function drawStone(x, y, color) {
            const cx = MARGIN + x*CELL_SIZE;
            const cy = MARGIN + y*CELL_SIZE;
            const radius = CELL_SIZE/2 - 2;
            
            const grad = ctx.createRadialGradient(cx-3, cy-3, 0, cx, cy, radius);
            if(color === "black") {
                grad.addColorStop(0, "#666");
                grad.addColorStop(1, "#000");
            } else {
                grad.addColorStop(0, "#fff");
                grad.addColorStop(1, "#ddd");
            }
            
            ctx.fillStyle = grad;
            ctx.beginPath();
            ctx.arc(cx, cy, radius, 0, Math.PI*2);
            ctx.fill();
        }
        
        function checkWin(x, y, color) {
            const dirs = [[1,0],[0,1],[1,1],[1,-1]];
            
            for(let [dx,dy] of dirs) {
                let count = 1;
                
                for(let dir of [1,-1]) {
                    let nx = x + dx*dir;
                    let ny = y + dy*dir;
                    while(nx>=0&&nx<BOARD_SIZE&&ny>=0&&ny<BOARD_SIZE&&board[nx][ny]===color) {
                        count++;
                        nx += dx*dir;
                        ny += dy*dir;
                    }
                }
                
                if(count === 5) return true;
            }
            return false;
        }
        
        function makeMove(x, y) {
            if(gameOver || aiThinking || board[x][y] !== null) return;
            
            moveHistory.push({x, y, player: currentPlayer});
            board[x][y] = currentPlayer;
            drawBoard();
            
            if(checkWin(x, y, currentPlayer)) {
                gameOver = true;
                const winner = currentPlayer === "black" ? "黑棋" : "白棋";
                updateStatus(winner + "获胜！🎉");
                
                const score = currentPlayer === "black" ? 100 : 80;
                saveScore(score);
                
                setTimeout(() => alert(winner + "获胜！"), 100);
                return;
            }
            
            if(moveHistory.length === BOARD_SIZE*BOARD_SIZE) {
                gameOver = true;
                updateStatus("平局！");
                return;
            }
            
            currentPlayer = currentPlayer === "black" ? "white" : "black";
            updateStatus((currentPlayer === "black" ? "黑棋" : "白棋") + "回合");
            
            if(gameMode === 1 && currentPlayer === "white" && !gameOver) {
                aiThinking = true;
                updateStatus("AI思考中...");
                setTimeout(() => aiMove(), 500);
            }
        }
        
               function aiMove(){
            if(!gameActive || currentPlayer!=="O") return;
            
            aiThinking = true;
            updateStatus("AI思考中...");
            
            setTimeout(()=>{
                // 确保有可用移动
                let available = [];
                for(let i=0; i<BOARD_SIZE; i++) {
                    for(let j=0; j<BOARD_SIZE; j++) {
                        if(board[i][j] === null) available.push({x:i, y:j});
                    }
                }
                
                if(available.length === 0) return;
                
                let move;
                
                // 检查AI是否可以获胜
                for(let pos of available) {
                    board[pos.x][pos.y] = "O";
                    if(checkWin(pos.x, pos.y, "O")) {
                        move = pos;
                        board[pos.x][pos.y] = null;
                        break;
                    }
                    board[pos.x][pos.y] = null;
                }
                
                // 阻止玩家获胜
                if(!move) {
                    for(let pos of available) {
                        board[pos.x][pos.y] = "X";
                        if(checkWin(pos.x, pos.y, "X")) {
                            move = pos;
                            board[pos.x][pos.y] = null;
                            break;
                        }
                        board[pos.x][pos.y] = null;
                    }
                }
                
                // 使用评估函数
                if(!move) {
                    move = findBestMove();
                }
                
                // 确保move有效
                if(move && board[move.x][move.y] === null) {
                    makeMove(move.x, move.y);
                } else if(available.length > 0) {
                    // 回退到随机选择
                    move = available[Math.floor(Math.random() * available.length)];
                    makeMove(move.x, move.y);
                }
                
                aiThinking = false;
            }, 300);
        }
        
               function findBestMove(){
            let bestScore = -Infinity;
            let bestMoves = [];
            
            for(let i=0; i<BOARD_SIZE; i++) {
                for(let j=0; j<BOARD_SIZE; j++) {
                    if(board[i][j] === null) {
                        const score = evaluateMove(i, j, "white") + evaluateMove(i, j, "black") * 0.8;
                        if(score > bestScore) {
                            bestScore = score;
                            bestMoves = [{x:i, y:j}];
                        } else if(score === bestScore) {
                            bestMoves.push({x:i, y:j});
                        }
                    }
                }
            }
            
            if(bestMoves.length === 0) return null;
            
            // 根据难度选择
            if(difficulty === 1) {
                // 简单：随机选择
                return bestMoves[Math.floor(Math.random() * bestMoves.length)];
            } else if(difficulty === 2) {
                // 中等：80%选择最好的，20%随机
                if(Math.random() > 0.2 && bestMoves.length > 0) {
                    return bestMoves[0];
                } else {
                    let allMoves = [];
                    for(let i=0; i<BOARD_SIZE; i++) {
                        for(let j=0; j<BOARD_SIZE; j++) {
                            if(board[i][j] === null) allMoves.push({x:i, y:j});
                        }
                    }
                    return allMoves[Math.floor(Math.random() * allMoves.length)];
                }
            } else {
                // 困难：选择最好的
                return bestMoves[0];
            }
        }
        
        function evaluateMove(x, y, color) {
            board[x][y] = color;
            let score = 0;
            
            const dirs = [[1,0],[0,1],[1,1],[1,-1]];
            for(let [dx,dy] of dirs) {
                let count = 1;
                let blocked = 0;
                
                for(let dir of [1,-1]) {
                    let nx = x + dx*dir;
                    let ny = y + dy*dir;
                    let c = 0;
                    while(nx>=0&&nx<BOARD_SIZE&&ny>=0&&ny<BOARD_SIZE&&board[nx][ny]===color) {
                        c++;
                        nx += dx*dir;
                        ny += dy*dir;
                    }
                    if(nx<0||nx>=BOARD_SIZE||ny<0||ny>=BOARD_SIZE||board[nx][ny]!==null) blocked++;
                    count += c;
                }
                
                if(count >= 5) score += 100000;
                else if(count === 4 && blocked === 0) score += 10000;
                else if(count === 4 && blocked === 1) score += 1000;
                else if(count === 3 && blocked === 0) score += 1000;
                else if(count === 3 && blocked === 1) score += 100;
                else if(count === 2 && blocked === 0) score += 100;
                else score += count;
            }
            
            board[x][y] = null;
            return score;
        }
        
        function updateStatus(msg) {
            document.getElementById("game-status").textContent = msg;
        }
        
        function undoMove() {
            if(moveHistory.length === 0 || gameOver) return;
            
            if(gameMode === 1 && moveHistory.length >= 2) {
                moveHistory.pop();
            }
            
            const last = moveHistory.pop();
            board[last.x][last.y] = null;
            currentPlayer = last.player;
            updateStatus((currentPlayer === "black" ? "黑棋" : "白棋") + "回合");
            drawBoard();
        }
        
        function saveScore(score) {
            fetch("?a=api&do=save&game=gomoku&score=" + score + "&csrf='.csrf().'").catch(()=>{});
        }
        
        canvas.addEventListener("click", (e) => {
            if(aiThinking) return;
            
            const rect = canvas.getBoundingClientRect();
            const x = Math.round((e.clientX - rect.left - MARGIN) / CELL_SIZE);
            const y = Math.round((e.clientY - rect.top - MARGIN) / CELL_SIZE);
            
            if(x>=0&&x<BOARD_SIZE&&y>=0&&y<BOARD_SIZE) {
                makeMove(x, y);
            }
        });
        
        window.setMode = function(mode) {
            gameMode = mode;
            document.getElementById("mode-pvp").classList.toggle("active", mode===0);
            document.getElementById("mode-pve").classList.toggle("active", mode===1);
            resetGame();
        };
        
        window.resetGame = initBoard;
        window.undoMove = undoMove;
        
        initBoard();
    })();
    </script>';
    htmlFoot();
}

// ==================== 井字棋游戏 ====================
function playTicTacToe() {
    $lv = intval($_GET['lv'] ?? 1);
    htmlHead('井字棋');
    echo '<style>
        .game-wrap{max-width:500px;margin:50px auto;padding:20px;text-align:center;}
        .game-info{margin-bottom:30px;}
        .game-info h2{font-size:32px;margin-bottom:10px;}
        .diff-tag{display:inline-block;padding:5px 15px;background:rgba(176,38,255,0.2);border:1px solid rgba(176,38,255,0.5);border-radius:15px;color:#b026ff;font-size:14px;}
        
        #board{display:grid;grid-template-columns:repeat(3,120px);grid-template-rows:repeat(3,120px);gap:12px;justify-content:center;margin:30px 0;}
        .cell{width:120px;height:120px;background:#1a1a25;border:2px solid #333;border-radius:12px;display:flex;align-items:center;justify-content:center;font-size:50px;cursor:pointer;transition:0.2s;}
        .cell:hover:not(.taken){background:#252535;border-color:#b026ff;}
        .cell.X{color:#00d4ff;text-shadow:0 0 20px rgba(0,212,255,0.5);}
        .cell.O{color:#ff00ff;text-shadow:0 0 20px rgba(255,0,255,0.5);}
        .cell.taken{cursor:not-allowed;}
        
        .status{font-size:20px;color:#ffd700;height:40px;display:flex;align-items:center;justify-content:center;}
        .controls{margin-top:30px;}
        .btn-game{padding:12px 30px;margin:0 10px;background:#333;color:#fff;border:none;border-radius:8px;cursor:pointer;font-size:16px;transition:0.3s;text-decoration:none;display:inline-block;}
        .btn-game:hover{background:#b026ff;}
        .btn-game.primary{background:#b026ff;}
        .btn-game.primary:hover{background:#7928ca;}
        
        .score-board{display:flex;justify-content:center;gap:40px;margin-top:30px;padding:20px;background:#1a1a25;border-radius:12px;}
        .score-item{text-align:center;}
        .score-num{font-size:28px;font-weight:bold;color:#00d4ff;}
        .score-label{color:#666;font-size:14px;}
    </style>';
    
    echo '<div class="game-wrap">';
    echo '<div class="game-info">';
    echo '<h2>⭕ 井字棋 ❌</h2>';
    echo '<span class="diff-tag">'.['','简单','中等','困难'][$lv].'模式</span>';
    echo '</div>';
    
    echo '<div class="status" id="game-status">你的回合 (X)</div>';
    
    echo '<div id="board">';
    for ($i=0;$i<9;$i++) echo '<div class="cell" data-idx="'.$i.'"></div>';
    echo '</div>';
    
    echo '<div class="controls">';
    echo '<button type="button" onclick="resetGame()" class="btn-game">重新开始</button>';
    echo '<a href="?a=game" class="btn-game primary">返回大厅</a>';
    echo '</div>';
    
    echo '<div class="score-board">';
    echo '<div class="score-item"><div class="score-num" id="score-win">0</div><div class="score-label">胜利</div></div>';
    echo '<div class="score-item"><div class="score-num" id="score-lose">0</div><div class="score-label">失败</div></div>';
    echo '<div class="score-item"><div class="score-num" id="score-draw">0</div><div class="score-label">平局</div></div>';
    echo '</div>';
    echo '</div>';
    
    echo '<script>
    (function(){
        let boardArr=["","","","","","","","",""];
        let currentPlayer="X";
        let gameActive=true;
        let difficulty='.$lv.';
        let winCount=0, loseCount=0, drawCount=0;
        
        const winPatterns=[[0,1,2],[3,4,5],[6,7,8],[0,3,6],[1,4,7],[2,5,8],[0,4,8],[2,4,6]];
        
        function checkWinner(player){
            return winPatterns.some(pattern=>{
                return pattern.every(idx=>boardArr[idx]===player);
            });
        }
        
        function isBoardFull(){
            return boardArr.every(cell=>cell!=="");
        }
        
        function updateStatus(msg,color){
            const status=document.getElementById("game-status");
            status.innerHTML=msg;
            if(color) status.style.color=color;
        }
        
        function makeMove(idx,player){
            boardArr[idx]=player;
            const cell=document.querySelector(".cell[data-idx=\""+idx+"\"]");
            cell.textContent=player;
            cell.classList.add(player,"taken");
        }
        
        function handleWin(winner){
            gameActive=false;
            if(winner==="X"){
                winCount++;
                document.getElementById("score-win").textContent=winCount;
                updateStatus("<span style=\'color:#39ff14;\'>你赢了! 🎉</span>","#39ff14");
                saveScore(difficulty*100);
            }else{
                loseCount++;
                document.getElementById("score-lose").textContent=loseCount;
                updateStatus("<span style=\'color:#ff6b6b;\'>AI获胜!</span>","#ff6b6b");
            }
        }
        
        function handleDraw(){
            gameActive=false;
            drawCount++;
            document.getElementById("score-draw").textContent=drawCount;
            updateStatus("<span style=\'color:#888;\'>平局!</span>","#888");
        }
        
        function getBestMove(){
            let bestScore=-Infinity;
            let move=0;
            for(let i=0;i<9;i++){
                if(boardArr[i]===""){
                    boardArr[i]="O";
                    let score=minimax(boardArr,false);
                    boardArr[i]="";
                    if(score>bestScore){
                        bestScore=score;
                        move=i;
                    }
                }
            }
            return move;
        }
        
        function minimax(board,isMaximizing){
            if(checkWinner("O")) return 10;
            if(checkWinner("X")) return -10;
            if(isBoardFull()) return 0;
            
            if(isMaximizing){
                let bestScore=-Infinity;
                for(let i=0;i<9;i++){
                    if(board[i]===""){
                        board[i]="O";
                        let score=minimax(board,false);
                        board[i]="";
                        bestScore=Math.max(score,bestScore);
                    }
                }
                return bestScore;
            }else{
                let bestScore=Infinity;
                for(let i=0;i<9;i++){
                    if(board[i]===""){
                        board[i]="X";
                        let score=minimax(board,true);
                        board[i]="";
                        bestScore=Math.min(score,bestScore);
                    }
                }
                return bestScore;
            }
        }
        
        function aiMove(){
            if(!gameActive || currentPlayer!=="O") return;
            
            updateStatus("AI思考中...","#ff00ff");
            
            setTimeout(()=>{
                let move;
                if(difficulty===1){
                    let available=[];
                    for(let i=0;i<9;i++) if(boardArr[i]==="") available.push(i);
                    move=available[Math.floor(Math.random()*available.length)];
                }else if(difficulty===2){
                    if(Math.random()>0.6){
                        move=getBestMove();
                    }else{
                        let available=[];
                        for(let i=0;i<9;i++) if(boardArr[i]==="") available.push(i);
                        move=available[Math.floor(Math.random()*available.length)];
                    }
                }else{
                    move=getBestMove();
                }
                
                makeMove(move,"O");
                
                if(checkWinner("O")){
                    handleWin("O");
                }else if(isBoardFull()){
                    handleDraw();
                }else{
                    currentPlayer="X";
                    updateStatus("你的回合 (X)","#ffd700");
                }
            },500);
        }
        
        function cellClick(e){
            const idx=parseInt(e.target.dataset.idx);
            if(!gameActive || boardArr[idx]!=="" || currentPlayer!=="X") return;
            
            makeMove(idx,"X");
            
            if(checkWinner("X")){
                handleWin("X");
            }else if(isBoardFull()){
                handleDraw();
            }else{
                currentPlayer="O";
                aiMove();
            }
        }
        
        function resetGame(){
            boardArr=["","","","","","","","",""];
            currentPlayer="X";
            gameActive=true;
            document.querySelectorAll(".cell").forEach(cell=>{
                cell.textContent="";
                cell.className="cell";
            });
            updateStatus("你的回合 (X)","#ffd700");
        }
        
        function saveScore(score){
            fetch("?a=api&do=save&game=tictactoe&score="+score+"&csrf='.csrf().'").catch(()=>{});
        }
        
        document.querySelectorAll(".cell").forEach(cell=>{
            cell.addEventListener("click",cellClick);
        });
        
        window.resetGame=resetGame;
    })();
    </script>';
    htmlFoot();
}

// ==================== 猜数字游戏 ====================
function playGuess() {
    htmlHead('猜数字');
    echo '<style>
        .guess-wrap{max-width:450px;margin:60px auto;padding:20px;text-align:center;}
        .guess-title{font-size:32px;margin-bottom:10px;}
        .guess-hint{color:#888;margin-bottom:30px;}
        
        .input-area{margin-bottom:30px;}
        .guess-input{width:200px;padding:20px;font-size:24px;text-align:center;background:#1a1a25;border:2px solid #444;color:#fff;border-radius:12px;margin-bottom:20px;}
        .guess-input:focus{outline:none;border-color:#b026ff;}
        
        .btn-guess{padding:15px 40px;background:#b026ff;color:#fff;border:none;border-radius:8px;cursor:pointer;font-size:18px;margin-right:10px;}
        .btn-guess:hover{background:#7928ca;}
        .btn-reset{padding:15px 30px;background:#333;color:#fff;border:none;border-radius:8px;cursor:pointer;font-size:18px;}
        
        .history{margin-top:30px;background:#1a1a25;border-radius:12px;padding:20px;text-align:left;}
        .history-title{color:#888;margin-bottom:15px;font-size:14px;}
        .history-item{display:flex;justify-content:space-between;padding:12px 0;border-bottom:1px solid #333;}
        .history-item:last-child{border-bottom:none;}
        .guess-num{color:#00d4ff;font-weight:bold;font-size:18px;}
        .guess-result{color:#ccc;}
        .guess-result.high{color:#ff6b6b;}
        .guess-result.low{color:#39ff14;}
        .guess-result.win{color:#ffd700;font-weight:bold;}
        
        .attempts{margin-top:20px;padding:15px;background:rgba(176,38,255,0.1);border-radius:8px;color:#b026ff;}
    </style>';
    
    echo '<div class="guess-wrap">';
    echo '<h2 class="guess-title">🔢 猜数字</h2>';
    echo '<p class="guess-hint">我想了一个 1 到 100 之间的数字</p>';
    
    echo '<div class="input-area">';
    echo '<input type="number" id="guess-num" class="guess-input" min="1" max="100" placeholder="?" autofocus>';
    echo '<br>';
    echo '<button type="button" onclick="makeGuess()" class="btn-guess">我猜!</button>';
    echo '<button type="button" onclick="resetGuess()" class="btn-reset">重来</button>';
    echo '</div>';
    
    echo '<div class="attempts" id="attempt-info">第 1 次尝试</div>';
    
    echo '<div class="history" id="guess-history">';
    echo '<div class="history-title">📝 猜测记录</div>';
    echo '<div id="history-content"></div>';
    echo '</div>';
    echo '</div>';
    
    echo '<script>
    (function(){
        let targetNum=Math.floor(Math.random()*100)+1;
        let attempts=0;
        const maxAttempts=10;
        let guessHistory=[];
        
        function updateHistory(){
            const container=document.getElementById("history-content");
            if(guessHistory.length===0){
                container.innerHTML="<div style=\'color:#666;text-align:center;padding:20px;\'>还没有猜测记录</div>";
                return;
            }
            container.innerHTML=guessHistory.map(h=>`
                <div class="history-item">
                    <span class="guess-num">#${h.attempts}: ${h.guess}</span>
                    <span class="guess-result ${h.resultClass}">${h.result}</span>
                </div>
            `).join("");
        }
        
        function makeGuess(){
            const input=document.getElementById("guess-num");
            const guess=parseInt(input.value);
            
            if(!guess||guess<1||guess>100){
                alert("请输入1-100的数字");
                return;
            }
            
            attempts++;
            let result,resultClass;
            
            if(guess===targetNum){
                result="🎉 恭喜你猜对了!";resultClass="win";
                input.disabled=true;
                let score=Math.max(10,110-attempts*10);
                saveScore(score);
                setTimeout(()=>alert("答案就是 "+targetNum+"!\\n你用了 "+attempts+" 次\\n获得 "+score+" 分"),100);
            }else if(guess<targetNum){
                result="📈 太小了，再大一点";resultClass="low";
            }else{
                result="📉 太大了，再小一点";resultClass="high";
            }
            
            guessHistory.unshift({guess,result,resultClass,attempts});
            updateHistory();
            
            document.getElementById("attempt-info").innerText=attempts>=maxAttempts?"最后一次机会!":"第 "+(attempts+1)+" 次尝试";
            input.value="";input.focus();
            
            if(attempts>=maxAttempts&&guess!==targetNum){
                input.disabled=true;
                alert("游戏结束! 答案是 "+targetNum);
            }
        }
        
        function resetGuess(){
            targetNum=Math.floor(Math.random()*100)+1;
            attempts=0;
            guessHistory=[];
            const input=document.getElementById("guess-num");
            input.disabled=false;
            input.value="";
            input.focus();
            document.getElementById("attempt-info").innerText="第 1 次尝试";
            updateHistory();
        }
        
        function saveScore(score){
            fetch("?a=api&do=save&game=guess&score="+score+"&csrf='.csrf().'").catch(()=>{});
        }
        
        document.getElementById("guess-num").addEventListener("keypress",e=>{
            if(e.key==="Enter") makeGuess();
        });
        
        window.makeGuess=makeGuess;
        window.resetGuess=resetGuess;
        updateHistory();
    })();
    </script>';
    htmlFoot();
}

// ==================== 公告功能 ====================
function doNotice() {
    $id = intval($_GET['id'] ?? 0);
    $db = DB::get();
    $n = $db->fetch("SELECT n.*,u.nickname FROM notices n LEFT JOIN users u ON n.created_by=u.id WHERE n.id=?", [$id]);
    if (!$n) redirect('./');
    
    htmlHead($n['title']);
    echo '<div style="max-width:800px;margin:60px auto;padding:20px;">';
    echo '<div style="margin-bottom:20px;"><a href="./" style="color:#888;">← 返回首页</a></div>';
    echo '<div style="background:#1a1a25;border:1px solid #333;border-radius:16px;padding:40px;">';
    echo '<div style="margin-bottom:20px;">'.($n['is_important']?'<span style="background:#ffd700;color:#000;padding:5px 15px;border-radius:4px;font-size:12px;font-weight:bold;">重要公告</span>':'<span style="background:#444;color:#aaa;padding:5px 15px;border-radius:4px;font-size:12px;">通知</span>').'</div>';
    echo '<h1 style="margin-bottom:20px;line-height:1.4;">'.h($n['title']).'</h1>';
    echo '<div style="color:#666;margin-bottom:30px;">发布者: '.h($n['nickname'] ?? '管理员').' · '.ago($n['created_at']).'</div>';
    echo '<div style="line-height:1.8;font-size:16px;color:#ccc;">'.nl2br(h($n['content'])).'</div>';
    echo '</div></div>';
    htmlFoot();
}

// ==================== API接口 ====================
function doApi() {
    if (!isLogin()) exit;
    $do = $_GET['do'] ?? '';
    $db = DB::get();
    
    if ($do == 'save') {
        $game = $_GET['game'] ?? '';
        $score = intval($_GET['score'] ?? 0);
        if ($game && $score > 0) {
            $db->query("INSERT INTO scores (user_id,game,score) VALUES (?,?,?)", 
                [$_SESSION['user_id'], $game, $score]);
        }
        echo 'ok';
    }
    exit;
}

// ==================== 用户系统 ====================
function doLogin() {
    $err = '';
    if ($_SERVER['REQUEST_METHOD'] == 'POST') {
        if (checkCsrf($_POST['csrf'] ?? '')) {
            $u = trim($_POST['u'] ?? '');
            $p = $_POST['p'] ?? '';
            try {
                $db = DB::get();
                $r = $db->fetch("SELECT * FROM users WHERE username=?", [$u]);
                if ($r && password_verify($p, $r['password'])) {
                    $_SESSION['user_id'] = $r['id'];
                    $_SESSION['nickname'] = $r['nickname'];
                    $_SESSION['is_admin'] = $r['is_admin'];
                    redirect('./');
                } else {
                    $err = '账号或密码错误';
                }
            } catch (Exception $e) {
                $err = '系统错误';
            }
        } else {
            $err = '验证失败';
        }
    }
    
    htmlHead('登录');
    echo '<style>
        .auth-wrap{min-height:80vh;display:flex;align-items:center;justify-content:center;padding:20px;}
        .auth-box{width:100%;max-width:400px;background:#1a1a25;border:1px solid #333;border-radius:20px;padding:40px;}
        .auth-title{text-align:center;margin-bottom:30px;}
        .auth-title h2{font-size:28px;margin-bottom:10px;}
        .auth-title p{color:#888;}
        .form-group{margin-bottom:20px;}
        .form-input{width:100%;padding:15px;background:#222;border:1px solid #444;color:#fff;border-radius:10px;font-size:16px;transition:0.3s;}
        .form-input:focus{outline:none;border-color:#b026ff;}
        .btn-submit{width:100%;padding:15px;background:#b026ff;color:#fff;border:none;border-radius:10px;font-size:16px;cursor:pointer;transition:0.3s;}
        .btn-submit:hover{background:#7928ca;}
        .auth-footer{text-align:center;margin-top:25px;color:#888;}
        .auth-footer a{color:#00d4ff;}
        .error{background:rgba(255,0,0,0.1);color:#f55;padding:12px;border-radius:8px;margin-bottom:20px;text-align:center;}
    </style>';
    
    echo '<div class="auth-wrap">';
    echo '<div class="auth-box">';
    echo '<div class="auth-title">';
    echo '<h2>👋 欢迎回来</h2>';
    echo '<p>登录 '.SITE_NAME.'</p>';
    echo '</div>';
    
    if ($err) echo '<div class="error">'.$err.'</div>';
    
    echo '<form method="post">';
    echo '<input type="hidden" name="csrf" value="'.csrf().'">';
    echo '<div class="form-group"><input type="text" name="u" class="form-input" placeholder="用户名" required></div>';
    echo '<div class="form-group"><input type="password" name="p" class="form-input" placeholder="密码" required></div>';
    echo '<button type="submit" class="btn-submit">登录</button>';
    echo '</form>';
    
    echo '<div class="auth-footer">还没有账号? <a href="?a=reg">立即注册</a></div>';
    echo '</div></div>';
    htmlFoot();
}

function doReg() {
    $err = '';
    if ($_SERVER['REQUEST_METHOD'] == 'POST') {
        if (checkCsrf($_POST['csrf'] ?? '')) {
            $u = trim($_POST['u'] ?? '');
            $p = $_POST['p'] ?? '';
            $n = trim($_POST['n'] ?? '');
            
            if (strlen($u) < 3) {
                $err = '用户名至少3位';
            } elseif (strlen($p) < 6) {
                $err = '密码至少6位';
            } elseif (empty($n)) {
                $err = '请输入昵称';
            } else {
                try {
                    $db = DB::get();
                    $db->query("INSERT INTO users (username,password,nickname) VALUES (?,?,?)",
                        [$u, password_hash($p, PASSWORD_DEFAULT), $n]);
                    redirect('?a=login');
                } catch (PDOException $e) {
                    $err = '用户名已存在';
                }
            }
        } else {
            $err = '验证失败';
        }
    }
    
    htmlHead('注册');
    echo '<style>
        .auth-wrap{min-height:80vh;display:flex;align-items:center;justify-content:center;padding:20px;}
        .auth-box{width:100%;max-width:400px;background:#1a1a25;border:1px solid #333;border-radius:20px;padding:40px;}
        .auth-title{text-align:center;margin-bottom:30px;}
        .auth-title h2{font-size:28px;margin-bottom:10px;}
        .auth-title p{color:#888;}
        .form-group{margin-bottom:20px;}
        .form-input{width:100%;padding:15px;background:#222;border:1px solid #444;color:#fff;border-radius:10px;font-size:16px;transition:0.3s;}
        .form-input:focus{outline:none;border-color:#39ff14;}
        .btn-submit{width:100%;padding:15px;background:#39ff14;color:#000;border:none;border-radius:10px;font-size:16px;cursor:pointer;transition:0.3s;font-weight:bold;}
        .btn-submit:hover{background:#2ecc12;}
        .auth-footer{text-align:center;margin-top:25px;color:#888;}
        .auth-footer a{color:#00d4ff;}
        .error{background:rgba(255,0,0,0.1);color:#f55;padding:12px;border-radius:8px;margin-bottom:20px;text-align:center;}
    </style>';
    
    echo '<div class="auth-wrap">';
    echo '<div class="auth-box">';
    echo '<div class="auth-title">';
    echo '<h2>🚀 加入班级</h2>';
    echo '<p>注册 '.SITE_NAME.' 账号</p>';
    echo '</div>';
    
    if ($err) echo '<div class="error">'.$err.'</div>';
    
    echo '<form method="post">';
    echo '<input type="hidden" name="csrf" value="'.csrf().'">';
    echo '<div class="form-group"><input type="text" name="u" class="form-input" placeholder="用户名 (建议学号)" required></div>';
    echo '<div class="form-group"><input type="text" name="n" class="form-input" placeholder="班级昵称 (如: 小明)" required></div>';
    echo '<div class="form-group"><input type="password" name="p" class="form-input" placeholder="密码 (至少6位)" required></div>';
    echo '<button type="submit" class="btn-submit">立即注册</button>';
    echo '</form>';
    
    echo '<div class="auth-footer">已有账号? <a href="?a=login">直接登录</a></div>';
    echo '</div></div>';
    htmlFoot();
}

// ==================== 模板函数 ====================
function htmlHead($title = '') {
    $fullTitle = $title ? $title.' - '.SITE_NAME : SITE_NAME;
    echo '<!DOCTYPE html><html lang="zh-CN"><head>';
    echo '<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">';
    echo '<title>'.h($fullTitle).'</title>';
    echo '<style>
        *{margin:0;padding:0;box-sizing:border-box;}
        body{background:#0a0a0f;color:#e0e0ff;font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,"Helvetica Neue",Arial,sans-serif;line-height:1.6;min-height:100vh;}
        a{color:#00d4ff;text-decoration:none;transition:0.3s;}
        a:hover{text-decoration:none;}
        ::-webkit-scrollbar{width:8px;}::-webkit-scrollbar-track{background:#0a0a0f;}::-webkit-scrollbar-thumb{background:#333;border-radius:4px;}::-webkit-scrollbar-thumb:hover{background:#555;}
    </style></head><body>';
    
    // 导航栏
    echo '<header style="background:rgba(15,15,25,0.95);backdrop-filter:blur(10px);border-bottom:1px solid #222;position:sticky;top:0;z-index:100;">';
    echo '<div style="max-width:1200px;margin:0 auto;padding:0 20px;display:flex;justify-content:space-between;align-items:center;height:70px;">';
    echo '<a href="./" style="display:flex;align-items:center;gap:10px;font-size:24px;font-weight:bold;color:#fff;">';
    echo '<span style="color:#b026ff;">⚡</span>'.SITE_NAME;
    echo '</a>';
    
    echo '<nav style="display:flex;gap:30px;align-items:center;">';
    echo '<a href="./" style="color:#888;font-size:15px;">首页</a>';
    echo '<a href="?a=forum" style="color:#888;font-size:15px;">论坛</a>';
    echo '<a href="?a=game" style="color:#888;font-size:15px;">游戏</a>';
    echo '<a href="?a=zjo" style="color:#888;font-size:15px;">ZJO</a>';
    
    if (isLogin()) {
        echo '<div style="display:flex;align-items:center;gap:15px;">';
        echo '<a href="#" style="color:#00d4ff;font-weight:500;">'.h($_SESSION['nickname']).'</a>';
        echo '<a href="?a=logout" style="color:#ff6b6b;font-size:13px;">退出</a>';
        echo '</div>';
    } else {
        echo '<div style="display:flex;gap:15px;">';
        echo '<a href="?a=login" style="padding:8px 20px;border:1px solid #444;border-radius:6px;color:#fff;font-size:14px;">登录</a>';
        echo '<a href="?a=reg" style="padding:8px 20px;background:#b026ff;border-radius:6px;color:#fff;font-size:14px;">注册</a>';
        echo '</div>';
    }
    echo '</nav></div></header>';
}


function htmlFoot() {
    echo '<footer style="background:#0f0f1a;border-top:1px solid #222;padding:40px 20px;margin-top:60px;">';
    echo '<div style="max-width:1200px;margin:0 auto;display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:20px;">';
    echo '<div style="color:#666;">&copy; '.date('Y').' '.SITE_NAME.' v'.VERSION.'</div>';
    echo '<div style="display:flex;gap:20px;">';
    echo '<a href="./" style="color:#666;font-size:14px;">首页</a>';
    echo '<a href="?a=forum" style="color:#666;font-size:14px;">论坛</a>';
    echo '<a href="?a=game" style="color:#666;font-size:14px;">游戏</a>';
    echo '<a href="?a=zjo" style="color:#666;font-size:14px;">ZJO</a>';
    echo '</div></div></footer></body></html>';
}