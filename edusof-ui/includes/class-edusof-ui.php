<?php
/**
 * Edusof UI: API pública del sistema de diseño y esqueleto del admin (ADR 0006).
 *
 * Contrato: Antigravity/docs/edusof-ui-contrato.md. Esta clase:
 * - lee la opción del sitio `edusof_ui` (interruptor, tema, modo) y la preferencia de modo de cada persona
 *   (meta de usuario `edusof_ui_mode`);
 * - pone las clases del <body> (edusof-ui, eds-screen, eds-theme-*, eds-mode-*, eds-staff) y carga el CSS;
 * - dibuja la cabecera propia, esconde la barra de WordPress y el pie al personal y los avisos de otros plugins;
 * - da la pantalla Ajustes › Apariencia, el campo «Modo de la apariencia» del perfil y la marca en wp-login.php.
 *
 * Con el interruptor apagado no añade nada al admin ni al acceso: todo se ve como antes.
 */

declare(strict_types=1);

defined('ABSPATH') || exit;

if (!class_exists('Edusof_UI', false)) {

    final class Edusof_UI
    {
        public const OPTION    = 'edusof_ui';
        public const USER_META = 'edusof_ui_mode';
        public const PAGE_SLUG = 'edusof-ui-appearance';
        public const THEMES    = ['azul', 'esmeralda', 'vino', 'terracota', 'grafito'];
        public const MODES     = ['light', 'dark', 'auto'];

        /** Valores por defecto de la opción del sitio. */
        public const DEFAULTS = ['enabled' => 1, 'theme' => 'azul', 'mode' => 'light'];

        /** Dominio de traducción del plugin cuya copia de la biblioteca se cargó. */
        private static string $domain = 'default';

        private static bool $booted = false;

        public static function boot(string $domain): void
        {
            if (self::$booted) {
                return;
            }
            self::$booted = true;
            self::$domain = '' !== $domain ? $domain : 'default';

            // Admin
            add_filter('admin_body_class', [self::class, 'admin_body_class']);
            add_action('admin_enqueue_scripts', [self::class, 'enqueue_admin']);
            add_action('admin_head', [self::class, 'admin_head']);
            add_action('in_admin_header', [self::class, 'render_header'], 5);
            add_action('in_admin_header', [self::class, 'filter_notices'], 1000);
            add_action('admin_menu', [self::class, 'register_page'], 99);
            add_action('admin_post_edusof_ui_save_appearance', [self::class, 'save_appearance']);

            // Perfil de usuario
            add_action('show_user_profile', [self::class, 'render_profile_field']);
            add_action('edit_user_profile', [self::class, 'render_profile_field']);
            add_action('personal_options_update', [self::class, 'save_profile_field']);
            add_action('edit_user_profile_update', [self::class, 'save_profile_field']);

            // Pantalla de acceso
            add_action('login_enqueue_scripts', [self::class, 'enqueue_login'], 20);
            add_filter('login_body_class', [self::class, 'login_body_class']);
            add_filter('login_headertext', [self::class, 'login_headertext'], 20);
        }

        /* ------------------------------------------------------------------ API del contrato */

        /** Opción del sitio validada. */
        public static function settings(): array
        {
            $saved = get_option(self::OPTION, []);
            $saved = is_array($saved) ? $saved : [];

            $theme = isset($saved['theme']) ? (string) $saved['theme'] : self::DEFAULTS['theme'];
            $mode  = isset($saved['mode']) ? (string) $saved['mode'] : self::DEFAULTS['mode'];

            return [
                'enabled' => array_key_exists('enabled', $saved) ? (empty($saved['enabled']) ? 0 : 1) : self::DEFAULTS['enabled'],
                'theme'   => in_array($theme, self::THEMES, true) ? $theme : self::DEFAULTS['theme'],
                'mode'    => in_array($mode, self::MODES, true) ? $mode : self::DEFAULTS['mode'],
            ];
        }

        /** Interruptor «Diseño Edusof». */
        public static function enabled(): bool
        {
            return 1 === self::settings()['enabled'];
        }

        /** Tema de la institución: azul | esmeralda | vino | terracota | grafito. */
        public static function theme(): string
        {
            return self::settings()['theme'];
        }

        /** Modo del sitio (sin la preferencia de la persona). */
        public static function site_mode(): string
        {
            return self::settings()['mode'];
        }

        /** Modo efectivo: la preferencia de la persona (si tiene) pasa por encima de la del sitio. */
        public static function mode(): string
        {
            $user_id = get_current_user_id();
            if ($user_id > 0) {
                $own = (string) get_user_meta($user_id, self::USER_META, true);
                if (in_array($own, self::MODES, true)) {
                    return $own;
                }
            }

            return self::site_mode();
        }

        /** True en las pantallas de los plugins (prefijos de page= registrados con el filtro edusof_ui_screens). */
        public static function is_plugin_screen(): bool
        {
            if (!is_admin() || !isset($_GET['page']) || !is_string($_GET['page'])) {
                return false;
            }
            $page = sanitize_text_field(wp_unslash($_GET['page']));
            if ('' === $page) {
                return false;
            }
            $prefixes = apply_filters('edusof_ui_screens', [self::PAGE_SLUG]);
            foreach ((array) $prefixes as $prefix) {
                if (is_string($prefix) && '' !== $prefix && str_starts_with($page, $prefix)) {
                    return true;
                }
            }

            return false;
        }

        /** Personal: quien no tiene el rol administrator. */
        public static function is_staff(): bool
        {
            if (!is_user_logged_in()) {
                return false;
            }

            return !in_array('administrator', (array) wp_get_current_user()->roles, true);
        }

        /** Dominio de traducción en uso (el del plugin cuya copia se cargó). */
        public static function text_domain(): string
        {
            return self::$domain;
        }

        /**
         * True si el esqueleto se aplica en esta petición del admin: diseño encendido y no es el editor de bloques,
         * el editor del sitio, el personalizador, un iframe ni AJAX (esas pantallas tienen su propio marco).
         */
        public static function active_here(): bool
        {
            if (!self::enabled() || !is_admin() || wp_doing_ajax() || (defined('IFRAME_REQUEST') && IFRAME_REQUEST)) {
                return false;
            }
            global $pagenow;
            if (in_array((string) $pagenow, ['site-editor.php', 'customize.php'], true)) {
                return false;
            }
            $screen = function_exists('get_current_screen') ? get_current_screen() : null;
            if ($screen instanceof WP_Screen && $screen->is_block_editor()) {
                return false;
            }

            return true;
        }

        /* ------------------------------------------------------------------ Textos */

        private static function t(string $text): string
        {
            // phpcs:ignore WordPress.WP.I18n.NonSingularStringLiteralText, WordPress.WP.I18n.NonSingularStringLiteralDomain
            return translate($text, self::$domain);
        }

        /** Nombres visibles de los temas. */
        public static function theme_labels(): array
        {
            return [
                'azul'      => self::t('Institutional blue'),
                'esmeralda' => self::t('Emerald green'),
                'vino'      => self::t('Wine'),
                'terracota' => self::t('Terracotta'),
                'grafito'   => self::t('Graphite'),
            ];
        }

        /** Nombres y descripciones de los modos. */
        public static function mode_labels(): array
        {
            return [
                'light' => [self::t('Light'), self::t('White background. Recommended for most people.')],
                'dark'  => [self::t('Dark'), self::t('Dark background. Easier on the eyes in low light.')],
                'auto'  => [self::t('Same as the device'), self::t('Follows the setting of the computer or phone.')],
            ];
        }

        /* ------------------------------------------------------------------ Admin: clases, CSS, cabecera */

        public static function admin_body_class($classes): string
        {
            $classes = (string) $classes;
            if (!self::active_here()) {
                return $classes;
            }
            $add = ['edusof-ui', 'eds-theme-' . self::theme(), 'eds-mode-' . self::mode()];
            if (self::is_plugin_screen()) {
                $add[] = 'eds-screen';
            }
            if (self::is_staff()) {
                $add[] = 'eds-staff';
            }

            return $classes . ' ' . implode(' ', $add) . ' ';
        }

        public static function enqueue_admin(): void
        {
            $page = self::PAGE_SLUG === (isset($_GET['page']) && is_string($_GET['page']) ? sanitize_key(wp_unslash($_GET['page'])) : '');
            // La pantalla Apariencia usa los componentes también con el diseño apagado (dentro de .eds-scope).
            if (!self::active_here() && !$page) {
                return;
            }
            wp_enqueue_style('edusof-ui', EDUSOF_UI_URL . 'assets/edusof-ui.css', [], EDUSOF_UI_VERSION);
        }

        public static function admin_head(): void
        {
            if (!self::active_here() || !self::is_staff()) {
                return;
            }
            // Sin la barra de WordPress (escritorio), el hueco que le reserva <html> sobra. En móvil se mantiene:
            // es la que lleva el botón del menú.
            echo '<style id="edusof-ui-staff">@media screen and (min-width:783px){html.wp-toolbar{padding-top:0}}</style>' . "\n";
        }

        /** Iniciales para el avatar de la cabecera. */
        private static function initials(string $name): string
        {
            $words = preg_split('/\s+/u', trim($name)) ?: [];
            $out   = '';
            foreach (array_slice(array_values(array_filter($words)), 0, 2) as $word) {
                $out .= function_exists('mb_substr') ? mb_substr($word, 0, 1) : substr($word, 0, 1);
            }

            return function_exists('mb_strtoupper') ? mb_strtoupper($out) : strtoupper($out);
        }

        /** URL del logo de la institución (opciones de EduSystem); '' si no hay. */
        private static function logo_url(string $option, string $size = 'medium'): string
        {
            $id = (int) get_option($option, 0);
            if ($id <= 0) {
                return '';
            }
            $url = wp_get_attachment_image_url($id, $size);

            return is_string($url) ? $url : '';
        }

        public static function render_header(): void
        {
            if (!self::active_here()) {
                return;
            }
            $user      = wp_get_current_user();
            $name      = '' !== trim((string) $user->display_name) ? (string) $user->display_name : (string) $user->user_login;
            $site      = (string) get_bloginfo('name');
            $logo      = self::logo_url('logo_admin');
            $home      = admin_url();
            $profile   = get_edit_profile_url((int) $user->ID);
            ?>
            <header class="eds-topbar" id="eds-topbar">
                <a class="eds-topbar__brand" href="<?php echo esc_url($home); ?>">
                    <?php if ('' !== $logo) : ?>
                        <span class="eds-topbar__logo"><img src="<?php echo esc_url($logo); ?>" alt="" /></span>
                    <?php endif; ?>
                    <span class="eds-topbar__names">
                        <strong><?php echo esc_html($site); ?></strong>
                        <span><?php echo esc_html(self::t('Management panel')); ?></span>
                    </span>
                </a>
                <div class="eds-topbar__user">
                    <a class="eds-topbar__profile" href="<?php echo esc_url($profile); ?>">
                        <span class="eds-topbar__avatar" aria-hidden="true"><?php echo esc_html(self::initials($name)); ?></span>
                        <span class="eds-topbar__name"><?php echo esc_html($name); ?></span>
                    </a>
                    <a class="eds-topbar__logout" href="<?php echo esc_url(wp_logout_url()); ?>"><?php echo esc_html(self::t('Log out')); ?></a>
                </div>
            </header>
            <?php
        }

        /* ------------------------------------------------------------------ Avisos de otros plugins */

        /** Carpetas (dentro de plugins/) de los plugins que traen esta biblioteca: sus avisos se mantienen. */
        private static function own_plugin_dirs(): array
        {
            $dirs = [];
            foreach ((array) ($GLOBALS['edusof_ui_copies'] ?? []) as $copy) {
                $dirs[] = basename(dirname((string) $copy['path']));
            }

            return array_values(array_unique(array_filter($dirs)));
        }

        /** Archivo donde está definido un callback; '' si no se puede saber. */
        private static function callback_file($callback): string
        {
            try {
                if (is_string($callback) && str_contains($callback, '::')) {
                    $callback = explode('::', $callback, 2);
                }
                if (is_array($callback) && 2 === count($callback)) {
                    $ref = new ReflectionMethod($callback[0], (string) $callback[1]);
                } elseif ($callback instanceof Closure || (is_string($callback) && function_exists($callback))) {
                    $ref = new ReflectionFunction($callback);
                } elseif (is_object($callback) && method_exists($callback, '__invoke')) {
                    $ref = new ReflectionMethod($callback, '__invoke');
                } else {
                    return '';
                }

                return (string) $ref->getFileName();
            } catch (ReflectionException $e) {
                return '';
            }
        }

        /**
         * Ajeno = definido en otro plugin (no EduSystem ni WP Certificates) o en el tema padre (Storefront). Lo del
         * core de WordPress, los mu-plugins y el tema hijo se mantiene: criterio conservador.
         */
        private static function is_foreign_file(string $file): bool
        {
            $file       = wp_normalize_path($file);
            $plugin_dir = trailingslashit(wp_normalize_path(WP_PLUGIN_DIR));
            if (str_starts_with($file, $plugin_dir)) {
                $folder = strtok(substr($file, strlen($plugin_dir)), '/');

                return !in_array((string) $folder, self::own_plugin_dirs(), true);
            }
            if (get_template() !== get_stylesheet()) {
                $parent = trailingslashit(wp_normalize_path(get_template_directory()));
                if (str_starts_with($file, $parent)) {
                    return true;
                }
            }

            return false;
        }

        /** En pantallas de los plugins y para el personal, quita los avisos de otros plugins. */
        public static function filter_notices(): void
        {
            if (!self::active_here() || (!self::is_plugin_screen() && !self::is_staff())) {
                return;
            }
            global $wp_filter;
            foreach (['admin_notices', 'all_admin_notices', 'user_admin_notices', 'network_admin_notices'] as $hook) {
                if (!isset($wp_filter[$hook]) || !($wp_filter[$hook] instanceof WP_Hook)) {
                    continue;
                }
                foreach ($wp_filter[$hook]->callbacks as $priority => $callbacks) {
                    foreach ($callbacks as $callback) {
                        $file = self::callback_file($callback['function']);
                        if ('' !== $file && self::is_foreign_file($file)) {
                            remove_action($hook, $callback['function'], (int) $priority);
                        }
                    }
                }
            }
        }

        /* ------------------------------------------------------------------ Ajustes › Apariencia */

        public static function register_page(): void
        {
            global $admin_page_hooks;
            // Bajo los ajustes de EduSystem si está activo; si no, bajo Certificación (WP Certificates).
            $parent = 'add_admin_form_settings_content';
            if (!defined('EDUSYSTEM_VERSION') || !isset($admin_page_hooks[$parent])) {
                $parent = isset($admin_page_hooks['add_admin_form_certificates_content'])
                    ? 'add_admin_form_certificates_content'
                    : (defined('EDUSYSTEM_VERSION') ? 'add_admin_form_settings_content' : 'options-general.php');
            }
            add_submenu_page(
                $parent,
                self::t('Appearance'),
                self::t('Appearance'),
                'manage_options',
                self::PAGE_SLUG,
                [self::class, 'render_page']
            );
        }

        public static function render_page(): void
        {
            if (!current_user_can('manage_options')) {
                wp_die(esc_html(self::t('Sorry, you are not allowed to do this.')), '', ['response' => 403]);
            }
            require EDUSOF_UI_PATH . 'views/appearance.php';
        }

        public static function save_appearance(): void
        {
            if (!current_user_can('manage_options')) {
                wp_die(esc_html(self::t('Sorry, you are not allowed to do this.')), '', ['response' => 403]);
            }
            check_admin_referer('edusof_ui_save_appearance');

            $old   = self::settings();
            $theme = isset($_POST['edusof_ui_theme']) ? sanitize_key(wp_unslash((string) $_POST['edusof_ui_theme'])) : '';
            $mode  = isset($_POST['edusof_ui_mode']) ? sanitize_key(wp_unslash((string) $_POST['edusof_ui_mode'])) : '';
            $new   = [
                'enabled' => empty($_POST['edusof_ui_enabled']) ? 0 : 1,
                'theme'   => in_array($theme, self::THEMES, true) ? $theme : $old['theme'],
                'mode'    => in_array($mode, self::MODES, true) ? $mode : $old['mode'],
            ];
            update_option(self::OPTION, $new, true);

            /** Para quien quiera registrarlo (por ejemplo, el log de EduSystem). */
            do_action('edusof_ui_appearance_saved', $new, $old);

            wp_safe_redirect(add_query_arg(['page' => self::PAGE_SLUG, 'updated' => '1'], admin_url('admin.php')));
            exit;
        }

        /* ------------------------------------------------------------------ Perfil: modo de la apariencia */

        public static function render_profile_field($user): void
        {
            if (!($user instanceof WP_User) || !self::enabled()) {
                return;
            }
            $current = (string) get_user_meta($user->ID, self::USER_META, true);
            $options = ['' => self::t('Same as the site')];
            foreach (self::mode_labels() as $key => $label) {
                $options[$key] = $label[0];
            }
            ?>
            <h2><?php echo esc_html(self::t('Appearance')); ?></h2>
            <table class="form-table" role="presentation">
                <tr class="edusof-ui-mode-wrap">
                    <th scope="row"><label for="edusof-ui-mode"><?php echo esc_html(self::t('Appearance mode')); ?></label></th>
                    <td>
                        <select name="edusof_ui_mode" id="edusof-ui-mode" aria-describedby="edusof-ui-mode-description">
                            <?php foreach ($options as $value => $label) : ?>
                                <option value="<?php echo esc_attr((string) $value); ?>" <?php selected($current, (string) $value); ?>><?php echo esc_html($label); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <p class="description" id="edusof-ui-mode-description"><?php echo esc_html(self::t('Light, dark or same as the device. Only applies when the Edusof design is on.')); ?></p>
                    </td>
                </tr>
            </table>
            <?php
        }

        /** El nonce del formulario del perfil ya lo comprueba WordPress (update-user_{id}) antes de este hook. */
        public static function save_profile_field($user_id): void
        {
            $user_id = (int) $user_id;
            if ($user_id <= 0 || !current_user_can('edit_user', $user_id) || !isset($_POST['edusof_ui_mode'])) {
                return;
            }
            $mode = sanitize_key(wp_unslash((string) $_POST['edusof_ui_mode']));
            if (in_array($mode, self::MODES, true)) {
                update_user_meta($user_id, self::USER_META, $mode);
            } else {
                delete_user_meta($user_id, self::USER_META);
            }
        }

        /* ------------------------------------------------------------------ Pantalla de acceso */

        private static function login_logo(): string
        {
            $logo = self::logo_url('logo_admin_login', 'full');

            return '' !== $logo ? $logo : self::logo_url('logo_admin', 'full');
        }

        public static function login_body_class($classes): array
        {
            $classes = (array) $classes;
            if (!self::enabled()) {
                return $classes;
            }
            $classes[] = 'edusof-ui';
            $classes[] = 'eds-theme-' . self::theme();
            $classes[] = 'eds-mode-' . self::mode();
            if ('' === self::login_logo()) {
                $classes[] = 'eds-no-logo';
            }

            return $classes;
        }

        public static function enqueue_login(): void
        {
            if (!self::enabled()) {
                return;
            }
            wp_enqueue_style('edusof-ui', EDUSOF_UI_URL . 'assets/edusof-ui.css', ['login'], EDUSOF_UI_VERSION);
            $logo = self::login_logo();
            if ('' !== $logo) {
                wp_add_inline_style('edusof-ui', 'body.edusof-ui.login{--eds-login-logo:url("' . esc_url_raw($logo) . '")}');
            }
        }

        /** Sin logo, el enlace de la cabecera del acceso muestra el nombre del sitio en lugar de «WordPress». */
        public static function login_headertext($text): string
        {
            if (!self::enabled()) {
                return (string) $text;
            }

            return (string) get_bloginfo('name');
        }
    }
}
