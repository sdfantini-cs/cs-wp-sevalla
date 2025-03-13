<?php
/**
 * Plugin Name: Cloudsome Redis Session Handler
 * Plugin URI: https://example.com/cloudsome-redis-session-handler
 * Description: Gestisce le sessioni di WordPress utilizzando Redis come backend di storage
 * Version: 2.0
 * Author: Cloudsome
 * Author URI: https://example.com
 * License: GPL-2.0+
 */

// Impedisci l'accesso diretto
if (!defined('ABSPATH')) {
    exit;
}

class Cloudsome_Redis_Sessions {
    /**
     * Istanza singleton
     */
    private static $instance = null;

    /**
     * Istanza Redis
     */
    private $redis = null;

    /**
     * Configurazione Redis
     */
    private $config = [
        'host' => '127.0.0.1',
        'port' => 6379,
        'database' => 0,
        'prefix' => 'cs_session_',
        'timeout' => 2,
        'read_timeout' => 2,
        'auth' => null,
        'ssl' => [
            'verify_peer' => false,
            'verify_peer_name' => false,
        ],
    ];

    /**
     * Stato della connessione
     */
    private $connected = false;
    
    /**
     * Stato delle sessioni
     */
    private $sessions_enabled = false;
    
    /**
     * Messaggio di errore di connessione
     */
    private $connection_error = '';

    /**
     * Track if sessions are enabled via database option
     */
    private $option_enabled = false;

    /**
     * Costruttore
     */
    private function __construct() {
        $this->load_config();
        
        // Load the enabled state from the option
        $this->option_enabled = get_option('cs_redis_sessions_enabled', false);
        
        // Aggiungi l'interfaccia di amministrazione senza inizializzare Redis
        add_action('admin_menu', [$this, 'add_admin_menu']);
        
        // Auto-connect if enabled or option is set
        if ($this->option_enabled || (defined('WP_REDIS_SESSIONS_AUTO_CONNECT') && WP_REDIS_SESSIONS_AUTO_CONNECT)) {
            add_action('plugins_loaded', [$this, 'init_redis'], 0);
            
            // Auto-enable if option is set or constant is defined
            if ($this->option_enabled || (defined('WP_REDIS_SESSIONS_AUTO_ENABLE') && WP_REDIS_SESSIONS_AUTO_ENABLE)) {
                add_action('init', [$this, 'init_sessions'], 0);
            }
        }
        
        // Aggiungi un pulsante di azione nella pagina dei plugin
        add_filter('plugin_action_links_' . plugin_basename(__FILE__), [$this, 'add_plugin_action_links']);
        
        // Verifica lo stato delle sessioni
        add_action('init', [$this, 'check_sessions_status'], 999);
    }
    
    /**
     * Verifica lo stato delle sessioni
     */
    public function check_sessions_status() {
        // Verifica se le sessioni sono già gestite da questo handler
        if (session_id() && isset($_SESSION['cs_redis_handler']) && $_SESSION['cs_redis_handler'] === true) {
            $this->sessions_enabled = true;
        }
    }

    /**
     * Aggiunge link nella pagina dei plugin
     */
    public function add_plugin_action_links($links) {
        $settings_link = '<a href="' . admin_url('tools.php?page=cloudsome-redis-sessions') . '">Impostazioni</a>';
        array_unshift($links, $settings_link);
        return $links;
    }

    /**
     * Carica la configurazione da wp-config.php
     */
    private function load_config() {
        // Carica la configurazione da wp-config.php se definita
        if (defined('WP_REDIS_SESSIONS_HOST')) {
            $this->config['host'] = WP_REDIS_SESSIONS_HOST;
        }
        
        if (defined('WP_REDIS_SESSIONS_PORT')) {
            $this->config['port'] = (int) WP_REDIS_SESSIONS_PORT;
        }
        
        if (defined('WP_REDIS_SESSIONS_DATABASE')) {
            $this->config['database'] = (int) WP_REDIS_SESSIONS_DATABASE;
        }
        
        if (defined('WP_REDIS_SESSIONS_PREFIX')) {
            $this->config['prefix'] = WP_REDIS_SESSIONS_PREFIX;
        }
        
        if (defined('WP_REDIS_SESSIONS_TIMEOUT')) {
            $this->config['timeout'] = (int) WP_REDIS_SESSIONS_TIMEOUT;
        }
        
        if (defined('WP_REDIS_SESSIONS_READ_TIMEOUT')) {
            $this->config['read_timeout'] = (int) WP_REDIS_SESSIONS_READ_TIMEOUT;
        }
        
        if (defined('WP_REDIS_SESSIONS_AUTH')) {
            $this->config['auth'] = WP_REDIS_SESSIONS_AUTH;
        }
        
        if (defined('WP_REDIS_SESSIONS_SSL')) {
            $this->config['ssl'] = WP_REDIS_SESSIONS_SSL;
        }
    }

    /**
     * Inizializza la connessione Redis
     */
    public function init_redis() {
        if (!class_exists('Redis')) {
            $this->connection_error = 'L\'estensione Redis PHP non è installata.';
            return false;
        }

        try {
            $this->redis = new Redis();
            
            // Connessione a Redis
            if (!empty($this->config['ssl']) && $this->config['ssl']['verify_peer'] !== false) {
                // Connessione SSL
                $this->connected = $this->redis->connect(
                    $this->config['host'],
                    $this->config['port'],
                    (int) $this->config['timeout'],
                    null,
                    (int) $this->config['read_timeout'],
                    $this->config['ssl']
                );
            } else {
                // Connessione standard
                $this->connected = $this->redis->connect(
                    $this->config['host'],
                    $this->config['port'],
                    (int) $this->config['timeout'],
                    null,
                    (int) $this->config['read_timeout']
                );
            }

            // Autenticazione se necessaria
            if ($this->connected && !empty($this->config['auth'])) {
                $this->redis->auth($this->config['auth']);
            }

            // Seleziona il database
            if ($this->connected && $this->config['database'] !== 0) {
                $this->redis->select($this->config['database']);
            }

            // Imposta il prefisso per le chiavi
            if ($this->connected) {
                $this->redis->setOption(Redis::OPT_PREFIX, $this->config['prefix']);
            }
            
            return $this->connected;
            
        } catch (Exception $e) {
            $this->connected = false;
            $this->connection_error = $e->getMessage();
            return false;
        }
    }

    /**
     * Inizializza il gestore delle sessioni
     */
    public function init_sessions() {
        if (!$this->connected && !$this->init_redis()) {
            return false;
        }

        // Imposta il gestore delle sessioni personalizzato
        if (!session_id()) {
            session_set_save_handler(
                [$this, 'session_open'],
                [$this, 'session_close'],
                [$this, 'session_read'],
                [$this, 'session_write'],
                [$this, 'session_destroy'],
                [$this, 'session_gc']
            );
            
            // Configura i cookie di sessione per essere compatibili con WordPress
            $cookie_secure = is_ssl();
            $cookie_httponly = true;
            $cookie_samesite = 'Lax';
            
            // Compatibilità con PHP 7.3+
            if (PHP_VERSION_ID >= 70300) {
                session_set_cookie_params([
                    'lifetime' => 0,
                    'path' => COOKIEPATH,
                    'domain' => COOKIE_DOMAIN,
                    'secure' => $cookie_secure,
                    'httponly' => $cookie_httponly,
                    'samesite' => $cookie_samesite
                ]);
            } else {
                session_set_cookie_params(
                    0,
                    COOKIEPATH,
                    COOKIE_DOMAIN,
                    $cookie_secure,
                    $cookie_httponly
                );
            }
            
            // Avvia la sessione
            session_start();
            
            // Imposta un flag per identificare che questa sessione è gestita da questo handler
            $_SESSION['cs_redis_handler'] = true;
            $this->sessions_enabled = true;
            
            // Update the option to enabled
            update_option('cs_redis_sessions_enabled', true);
            $this->option_enabled = true;
            
            return true;
        }
        
        return false;
    }
    
    /**
     * Disabilita la gestione delle sessioni
     */
    public function disable_sessions() {
        if (session_id()) {
            // Cancella la sessione
            session_destroy();
            $this->sessions_enabled = false;
        }
        
        // Update the option to disabled
        update_option('cs_redis_sessions_enabled', false);
        $this->option_enabled = false;
        
        return true;
    }

    /**
     * Handler per l'apertura della sessione
     */
    public function session_open($save_path, $session_name) {
        return $this->connected;
    }

    /**
     * Handler per la chiusura della sessione
     */
    public function session_close() {
        return true;
    }

    /**
     * Handler per la lettura della sessione
     */
    public function session_read($session_id) {
        if (!$this->connected) {
            return '';
        }
        
        $data = $this->redis->get($session_id);
        return $data !== false ? $data : '';
    }

    /**
     * Handler per la scrittura della sessione
     */
    public function session_write($session_id, $session_data) {
        if (!$this->connected) {
            return false;
        }
        
        // Imposta i dati della sessione con una scadenza di 24 ore
        return $this->redis->setex($session_id, 86400, $session_data);
    }

    /**
     * Handler per la distruzione della sessione
     */
    public function session_destroy($session_id) {
        if (!$this->connected) {
            return false;
        }
        
        return $this->redis->del($session_id) > 0;
    }

    /**
     * Handler per la garbage collection della sessione
     */
    public function session_gc($maxlifetime) {
        // Redis gestisce automaticamente la scadenza delle chiavi
        return true;
    }

    /**
     * Aggiunge la pagina di amministrazione
     */
    public function add_admin_menu() {
        add_management_page(
            'Cloudsome Redis Sessions',
            'Redis Sessions',
            'manage_options',
            'cloudsome-redis-sessions',
            [$this, 'admin_page']
        );
    }

    /**
     * Gestisce le azioni di amministrazione
     */
    private function handle_admin_actions() {
        if (isset($_POST['wp_redis_test_connection']) && check_admin_referer('wp_redis_test_connection')) {
            // Inizializza la connessione Redis
            $this->init_redis();
            
            // Reindirizza alla stessa pagina per evitare riinvii del modulo
            wp_redirect(add_query_arg(['page' => 'cloudsome-redis-sessions', 'connection_tested' => $this->connected ? '1' : '0'], admin_url('tools.php')));
            exit;
        }
        
        if (isset($_POST['wp_redis_enable_sessions']) && check_admin_referer('wp_redis_enable_sessions')) {
            // Abilita la gestione delle sessioni
            if ($this->connected || $this->init_redis()) {
                $result = $this->init_sessions();
                
                // Reindirizza alla stessa pagina
                wp_redirect(add_query_arg(['page' => 'cloudsome-redis-sessions', 'sessions_enabled' => $result ? '1' : '0'], admin_url('tools.php')));
                exit;
            }
        }
        
        if (isset($_POST['wp_redis_disable_sessions']) && check_admin_referer('wp_redis_disable_sessions')) {
            // Disabilita la gestione delle sessioni
            $result = $this->disable_sessions();
            
            // Reindirizza alla stessa pagina
            wp_redirect(add_query_arg(['page' => 'cloudsome-redis-sessions', 'sessions_disabled' => $result ? '1' : '0'], admin_url('tools.php')));
            exit;
        }
    }

    /**
     * Renderizza la pagina di amministrazione
     */
    public function admin_page() {
        // Gestisci le azioni di amministrazione
        $this->handle_admin_actions();
        
        // Stili CSS inline per il layout a due colonne
        ?>
        <style>
            .cs-redis-container {
                display: flex;
                flex-wrap: wrap;
                margin-right: -15px;
                margin-left: -15px;
            }
            .cs-redis-col {
                flex: 0 0 48%;
                max-width: 48%;
                padding-right: 15px;
                padding-left: 15px;
                box-sizing: border-box;
            }
            @media (max-width: 768px) {
                .cs-redis-col {
                    flex: 0 0 100%;
                    max-width: 100%;
                }
            }
            .cs-redis-card {
                background: #fff;
                border: 1px solid #ccd0d4;
                box-shadow: 0 1px 1px rgba(0,0,0,.04);
                padding: 20px;
                margin-bottom: 20px;
                border-radius: 3px;
            }
        </style>
        
        <div class="wrap">
            <h1>Cloudsome Redis Session Handler</h1>
            
            <?php if (isset($_GET['connection_tested'])): ?>
                <?php if ($_GET['connection_tested'] == '1'): ?>
                    <div class="notice notice-success is-dismissible">
                        <p>Connessione a Redis stabilita con successo!</p>
                    </div>
                <?php else: ?>
                    <div class="notice notice-error is-dismissible">
                        <p>Impossibile connettersi a Redis. Errore: <?php echo esc_html($this->connection_error); ?></p>
                    </div>
                <?php endif; ?>
            <?php endif; ?>
            
            <?php if (isset($_GET['sessions_enabled']) && $_GET['sessions_enabled'] == '1'): ?>
                <div class="notice notice-success is-dismissible">
                    <p>La gestione delle sessioni Redis è stata abilitata.</p>
                </div>
            <?php endif; ?>
            
            <?php if (isset($_GET['sessions_disabled']) && $_GET['sessions_disabled'] == '1'): ?>
                <div class="notice notice-success is-dismissible">
                    <p>La gestione delle sessioni Redis è stata disabilitata.</p>
                </div>
            <?php endif; ?>
            
            <div class="cs-redis-container">
                <div class="cs-redis-col">
                    <div class="cs-redis-card">
                        <h2>Configurazione Redis</h2>
                        <p>Configurazione attuale:</p>
                        <ul>
                            <li>Host: <?php echo esc_html($this->config['host']); ?></li>
                            <li>Porta: <?php echo esc_html($this->config['port']); ?></li>
                            <li>Database: <?php echo esc_html($this->config['database']); ?></li>
                            <li>Prefisso: <?php echo esc_html($this->config['prefix']); ?></li>
                            <li>Timeout connessione: <?php echo esc_html($this->config['timeout']); ?> secondi</li>
                            <li>Timeout lettura: <?php echo esc_html($this->config['read_timeout']); ?> secondi</li>
                            <li>Autenticazione: <?php echo !empty($this->config['auth']) ? 'Configurata' : 'Non configurata'; ?></li>
                            <li>SSL: <?php echo !empty($this->config['ssl']) && $this->config['ssl']['verify_peer'] !== false ? 'Abilitato' : 'Disabilitato'; ?></li>
                        </ul>
                        
                        <form method="post" action="">
                            <?php wp_nonce_field('wp_redis_test_connection'); ?>
                            <p><input type="submit" name="wp_redis_test_connection" class="button button-primary" value="Verifica connessione"></p>
                        </form>
                        
                        <?php if ($this->connected): ?>
                            <?php if ($this->option_enabled): ?>
                                <form method="post" action="">
                                    <?php wp_nonce_field('wp_redis_disable_sessions'); ?>
                                    <p><input type="submit" name="wp_redis_disable_sessions" class="button button-secondary" value="Disabilita gestione sessioni"></p>
                                </form>
                            <?php else: ?>
                                <form method="post" action="">
                                    <?php wp_nonce_field('wp_redis_enable_sessions'); ?>
                                    <p><input type="submit" name="wp_redis_enable_sessions" class="button button-secondary" value="Abilita gestione sessioni"></p>
                                </form>
                            <?php endif; ?>
                        <?php endif; ?>
                    </div>
                </div>
                
                <div class="cs-redis-col">
                    <?php if ($this->connected): ?>
                    <div class="cs-redis-card">
                        <h2>Stato della connessione</h2>
                        <p style="color: green; font-weight: bold;">✓ Connesso a Redis</p>
                        
                        <?php
                        // Recupera le informazioni sulle chiavi
                        $keys_count = 0;
                        try {
                            $pattern = '*';
                            $keys = $this->redis->keys($pattern);
                            $keys_count = count($keys);
                            
                            // Info server
                            $info = $this->redis->info();
                        } catch (Exception $e) {
                            echo '<p style="color: red;">Errore nel recupero delle informazioni: ' . esc_html($e->getMessage()) . '</p>';
                        }
                        ?>
                        
                        <h3>Statistiche</h3>
                        <p>Numero di chiavi di sessione: <?php echo esc_html($keys_count); ?></p>
                        
                        <?php if (isset($info)): ?>
                        <h3>Informazioni Redis</h3>
                        <p>Versione: <?php echo esc_html($info['redis_version']); ?></p>
                        <p>Memoria utilizzata: <?php echo esc_html(round($info['used_memory'] / 1024 / 1024, 2)); ?> MB</p>
                        <p>Clients connessi: <?php echo esc_html($info['connected_clients']); ?></p>
                        <?php endif; ?>
                        
                        <h3>Stato sessioni</h3>
                        <p><?php echo $this->option_enabled ? 
                            '<span style="color: green; font-weight: bold;">✓ Sessioni Redis attive</span>' : 
                            '<span style="color: orange; font-weight: bold;">○ Sessioni Redis non attive</span>'; ?></p>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
            
            <div class="cs-redis-card">
                <h2>Guida alla configurazione</h2>
                <p>Aggiungi le seguenti definizioni al tuo file wp-config.php per configurare il plugin:</p>
                <pre>
// Configurazione base
define('WP_REDIS_SESSIONS_HOST', 'il-tuo-host-redis');
define('WP_REDIS_SESSIONS_PORT', 6379);
define('WP_REDIS_SESSIONS_DATABASE', 0);
define('WP_REDIS_SESSIONS_PREFIX', 'cs_session_');

// Auto-connessione all'avvio (default: disabilitata)
define('WP_REDIS_SESSIONS_AUTO_CONNECT', true);

// Auto-abilitazione sessioni all'avvio (default: disabilitata)
define('WP_REDIS_SESSIONS_AUTO_ENABLE', true);

// Configurazione avanzata (opzionale)
define('WP_REDIS_SESSIONS_TIMEOUT', 2);
define('WP_REDIS_SESSIONS_READ_TIMEOUT', 2);
define('WP_REDIS_SESSIONS_AUTH', 'password-se-necessaria');

// Configurazione SSL (opzionale)
define('WP_REDIS_SESSIONS_SSL', [
    'verify_peer' => true,
    'verify_peer_name' => true,
    'cafile' => '/path/to/ca/file'
]);
                </pre>
            </div>
        </div>
        <?php
    }

    /**
     * Ottiene l'istanza singleton
     */
    public static function get_instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        
        return self::$instance;
    }
}

// Inizializza il plugin
Cloudsome_Redis_Sessions::get_instance();