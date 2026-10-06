<?php
declare(strict_types=1);

namespace Squuad\Certificados;

defined('ABSPATH') || exit;

/**
 * Registro de métodos de variables (ADR 0005). Cada plugin propio registra, en su código, una función por dato bajo un
 * identificador "proveedor.clave" (p. ej. edusystem.student_name). La base de datos solo guarda ese identificador; lo
 * que se ejecuta es siempre la función de este registro.
 */
final class VariableMethods
{
    public const TYPES = ['text', 'html', 'condition'];
    public const OFFERED = ['all', 'document', 'email'];

    /** @var array<string, array> identificador => definición */
    private static array $methods = [];

    /** @var array<string, string> proveedor => archivo del plugin que lo reclamó */
    private static array $providers = [];

    /**
     * Registra un método. El plugin se identifica por el archivo desde el que se llama (no por lo que declare).
     * Devuelve false, y lo anota en el log, si algo no es válido.
     */
    public static function register(string $provider, string $key, array $definition, string $caller_file): bool
    {
        $error = self::validate($provider, $key, $definition, $caller_file);
        if ('' !== $error) {
            Log::add(sprintf('Método de variable %s.%s no registrado: %s', $provider, $key, $error), 'variable_method_error');
            return false;
        }
        $plugin = OwnPlugins::plugin_of_file($caller_file);
        self::$providers[$provider] = $plugin;
        self::$methods[$provider . '.' . $key] = [
            'id' => $provider . '.' . $key,
            'provider' => $provider,
            'key' => $key,
            'plugin' => $plugin,
            // label y group pueden ser funciones: se traducen al mostrarse (no antes de init, WordPress 6.7+)
            'label' => $definition['label'],
            'group' => $definition['group'] ?? '',
            'type' => (string) $definition['type'],
            'offered' => (string) ($definition['offered'] ?? 'all'),
            'subject' => (bool) ($definition['subject'] ?? true),
            'sensitive' => (bool) ($definition['sensitive'] ?? false),
            // Formato: el valor va en mayúsculas (<span class="text-uppercase">), como el 'wrap' de process_template()
            'wrap' => (bool) ($definition['wrap'] ?? false),
            // ADR 0009 de Edusof: el método devuelve el id de una cuenta de WordPress (texto con solo dígitos) y puede
            // designar a quien firma un puesto «firmante por variable» ({{signature_var_<clave>}}). signer_label: nombre
            // del puesto en el recuadro de firma (p. ej. «Representante»); por defecto, la descripción
            'account' => (bool) ($definition['account'] ?? false),
            'signer_label' => $definition['signer_label'] ?? '',
            // ADR 0012 de Edusof: quien firma por esta variable lo hace también en representación del titular (p. ej. el
            // representante de un estudiante): acepta su propio texto de consentimiento
            'signs_on_behalf' => !empty($definition['account']) && !empty($definition['signs_on_behalf']),
            'callback' => $definition['callback'],
        ];

        return true;
    }

    /** Todos los métodos registrados (de cualquier plugin), con descripción y grupo ya como texto. */
    public static function all(): array
    {
        return array_map([self::class, 'resolve_texts'], self::$methods);
    }

    private static function resolve_texts(array $method): array
    {
        foreach (['label', 'group', 'signer_label'] as $field) {
            if (is_callable($method[$field])) {
                $method[$field] = (string) call_user_func($method[$field]);
            }
            $method[$field] = (string) $method[$field];
        }

        return $method;
    }

    /**
     * Métodos que se pueden elegir y ejecutar: de un plugin propio activado por el administrador y fuera de cuarentena.
     */
    public static function available(): array
    {
        return array_filter(self::all(), static function (array $method): bool {
            return OwnPlugins::is_enabled($method['plugin']) && !Quarantine::has($method['id']);
        });
    }

    /** Método disponible por su identificador, o null (no registrado, plugin no activado o en cuarentena). */
    public static function get_available(string $id): ?array
    {
        return self::available()[$id] ?? null;
    }

    private static function validate(string $provider, string $key, array $definition, string $caller_file): string
    {
        if (!preg_match('/^[a-z][a-z0-9_]{1,39}$/', $provider) || !preg_match('/^[a-z][a-z0-9_]{1,59}$/', $key)) {
            return 'el proveedor y la clave deben ser identificadores en minúsculas';
        }
        $plugin = OwnPlugins::plugin_of_file($caller_file);
        if ('' === $plugin) {
            return 'solo se registra desde el código de un plugin';
        }
        if (!OwnPlugins::is_own($plugin)) {
            return 'el plugin ' . $plugin . ' no es propio (su ficha no menciona a EduSof ni a Squuad)';
        }
        if (isset(self::$providers[$provider]) && self::$providers[$provider] !== $plugin) {
            return 'el proveedor ' . $provider . ' ya pertenece a ' . self::$providers[$provider];
        }
        // core está reservado: las variables generales no son métodos elegibles, las calcula wp-certificates siempre
        if ('core' === $provider) {
            return 'el proveedor core está reservado para las variables generales';
        }
        if (isset(self::$methods[$provider . '.' . $key])) {
            return 'ya está registrado';
        }
        $label = $definition['label'] ?? '';
        if (!is_callable($label) && '' === trim((string) $label)) {
            return 'falta la descripción';
        }
        if (!in_array($definition['type'] ?? '', self::TYPES, true)) {
            return 'tipo no válido (text, html o condition)';
        }
        if (!in_array($definition['offered'] ?? 'all', self::OFFERED, true)) {
            return 'dónde se ofrece no es válido (all, document o email)';
        }
        // Un método que designa firmantes devuelve texto (el id de la cuenta), nunca HTML ni una condición
        if (!empty($definition['account']) && 'text' !== ($definition['type'] ?? '')) {
            return 'un método de cuenta (account) debe ser de tipo text';
        }
        if (!is_callable($definition['callback'] ?? null)) {
            return 'la función no es invocable';
        }

        return '';
    }
}
