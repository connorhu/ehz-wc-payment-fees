<?php
/**
 * Plugin Name: EHZ WC Payment Fees
 * Description: Offline fizetési mód + szállításhoz kötött fizetési díjak (Packeta, Foxpost, stb.).
 * Author: Károly Gossler & ChatGPT
 * Version: 0.1.0
 * Text Domain: ehz-wc-payment-fees
 */

declare(strict_types=1);

namespace Ehz\WcPaymentFees;

if ( ! defined('ABSPATH') ) {
    exit;
}

/**
 * Egyszerű helper: WooCommerce elérhető-e?
 */
function is_woocommerce_active(): bool
{
    return class_exists('\WooCommerce');
}

/**
 * Bootstrap
 */
add_action('plugins_loaded', __NAMESPACE__ . '\\bootstrap_ehz_wc_payment_fees');

function bootstrap_ehz_wc_payment_fees(): void
{
    if ( ! is_woocommerce_active() ) {
        // WooCommerce nélkül csendben kilépünk.
        return;
    }

    // Gondoskodunk róla, hogy a WooCommerce alap gateway osztályai betöltődjenek
    // mielőtt a saját gateway osztályunkat definiálnánk.
    load_offline_gateway_class();
    add_action('woocommerce_loaded', __NAMESPACE__ . '\\load_offline_gateway_class');

    Plugin::instance();
}

/**
 * Offline fizetési mód osztály betöltése csak akkor, ha a WooCommerce már elérhető.
 */
function load_offline_gateway_class(): void
{
    if (class_exists(__NAMESPACE__ . '\\OfflineGateway')) {
        return;
    }

    if (! class_exists('\\WC_Payment_Gateway')) {
        return;
    }

    /**
     * Offline fizetési mód (ehz_wc_offline_cash)
     */
    final class OfflineGateway extends \WC_Payment_Gateway
    {
        protected string $instructions = '';

        public function __construct()
        {
            $config = Plugin::$gateway_configs[get_class($this)] ?? null;

            $this->id                 = $config['id'] ?? 'ehz_wc_offline_cash';
            $this->method_title       = $config['method_title'] ?? __('Offline payment (EHZ)', 'ehz-wc-payment-fees');
            $this->method_description = $config['method_description'] ?? __('Custom offline payment method provided by EHZ plugin.', 'ehz-wc-payment-fees');
            $this->has_fields         = false;

            // Load the settings.
            $this->init_form_fields();
            $this->init_settings();

            $this->title        = $this->get_option('title', $config['title'] ?? __('Offline payment', 'ehz-wc-payment-fees'));
            $this->description  = $this->get_option('description', $config['description'] ?? '');
            $this->instructions = $this->get_option('instructions', $config['instructions'] ?? '');
            $this->enabled      = $this->get_option('enabled', $config['enabled'] ?? 'no');

            add_action('woocommerce_update_options_payment_gateways_' . $this->id, [$this, 'process_admin_options']);
        }

        public function init_form_fields(): void
        {
            $this->form_fields = [
                'enabled' => [
                    'title'   => __('Enable/Disable', 'ehz-wc-payment-fees'),
                    'type'    => 'checkbox',
                    'label'   => __('Enable this offline payment method', 'ehz-wc-payment-fees'),
                    'default' => 'no',
                ],
                'title' => [
                    'title'       => __('Title', 'ehz-wc-payment-fees'),
                    'type'        => 'text',
                    'description' => __('This controls the title which the user sees during checkout.', 'ehz-wc-payment-fees'),
                    'default'     => __('Offline payment', 'ehz-wc-payment-fees'),
                    'desc_tip'    => true,
                ],
                'description' => [
                    'title'       => __('Description', 'ehz-wc-payment-fees'),
                    'type'        => 'textarea',
                    'description' => __('Payment method description that the customer will see on your checkout.', 'ehz-wc-payment-fees'),
                    'default'     => '',
                    'desc_tip'    => true,
                ],
                'instructions' => [
                    'title'       => __('Instructions', 'ehz-wc-payment-fees'),
                    'type'        => 'textarea',
                    'description' => __('Instructions that will be added to the thank you page and emails.', 'ehz-wc-payment-fees'),
                    'default'     => '',
                    'desc_tip'    => true,
                ],
            ];
        }

        /**
         * Output for the order received page.
         */
        public function thankyou_page(): void
        {
            if ($this->instructions) {
                echo wpautop(wptexturize($this->instructions));
            }
        }

        /**
         * Add content to the WC emails.
         */
        public function email_instructions(\WC_Order $order, bool $sent_to_admin, bool $plain_text = false): void
        {
            if ($this->instructions && ! $sent_to_admin && $order->has_status('on-hold') && $order->get_payment_method() === $this->id) {
                echo wpautop(wptexturize($this->instructions)) . PHP_EOL;
            }
        }

        public function process_payment($order_id): array
        {
            $order = wc_get_order($order_id);

            $order->update_status('on-hold', __('Awaiting offline payment', 'ehz-wc-payment-fees'));

            $order->reduce_order_stock();

            WC()->cart->empty_cart();

            return [
                'result'   => 'success',
                'redirect' => $this->get_return_url($order),
            ];
        }

        public function is_available(): bool
        {
            if ('yes' !== $this->get_option('enabled')) {
                return false;
            }

            if (is_admin()) {
                return true;
            }

            $gateway_id = $this->id;
            $gateway_fee = Plugin::get_fee_for_current_cart($gateway_id);
            $has_shipping_rule = ! empty(array_filter(Plugin::get_shipping_filters(), static function (array $rule) use ($gateway_id): bool {
                return (string) ($rule['gateway_id'] ?? '') === $gateway_id;
            }));

            if ($gateway_fee <= 0 && ! $has_shipping_rule) {
                return false;
            }

            return parent::is_available();
        }
    }
}

/**
 * Fő plugin osztály (singleton)
 */
final class Plugin
{
    private static ?self $instance = null;
    private const OPTION_FEE_RULES = 'ehz_wc_payment_fee_rules';
    private const OPTION_OFFLINE_METHODS = 'ehz_wc_offline_methods';
    private const OPTION_SHIPPING_FILTERS = 'ehz_wc_payment_shipping_filters';

    /**
     * Gateway config tároló a dinamikusan generált osztályokhoz.
     *
     * @var array<string,array>
     */
    public static array $gateway_configs = [];

    public static function instance(): self
    {
        if (null === self::$instance) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    private function __construct()
    {
        $this->init_hooks();
    }

    private function init_hooks(): void
    {
        // Új offline fizetési módok regisztrálása (dinamikusan definiálható)
        add_filter('woocommerce_payment_gateways', [$this, 'register_offline_gateway']);

        // Admin: beállítás oldal
        add_action('admin_menu', [$this, 'register_admin_menu']);
        add_action('admin_init', [$this, 'register_settings']);

        // Admin: WooCommerce > Settings > Payments alá illesztett szekció
        add_filter('woocommerce_get_sections_checkout', [$this, 'add_checkout_section']);
        add_filter('woocommerce_get_settings_checkout', [$this, 'add_checkout_settings'], 10, 2);
        add_action('woocommerce_admin_field_ehz_offline_methods', [$this, 'render_offline_methods_field']);
        add_action('woocommerce_settings_save_checkout', [$this, 'handle_checkout_settings_save']);

        // Díj hozzáadása a kosárhoz
        add_action('woocommerce_cart_calculate_fees', [$this, 'add_fee_to_cart']);

        // Fizetési mód címének kiegészítése a díjjal
        add_filter('woocommerce_gateway_title', [$this, 'append_fee_to_gateway_title'], 10, 2);

        // Fizetési módok szűrése szállítási mód alapján
        add_filter('woocommerce_available_payment_gateways', [$this, 'filter_gateways_by_shipping']);
    }

    /**
     * Új offline fizetési mód regisztrálása
    */
    public function register_offline_gateway(array $gateways): array
    {
        load_offline_gateway_class();

        foreach (self::get_offline_methods() as $method) {
            $class_name = $this->ensure_gateway_class($method);
            if ($class_name !== null) {
                $gateways[] = $class_name;
            }
        }

        // Ha nincs definiált egyéni mód, biztosítunk egy alap offline metódust
        if (empty(self::get_offline_methods())) {
            $gateways[] = OfflineGateway::class;
            self::$gateway_configs[OfflineGateway::class] = [
                'id'                 => 'ehz_wc_offline_cash',
                'title'              => __('Offline payment', 'ehz-wc-payment-fees'),
                'description'        => '',
                'instructions'       => '',
                'enabled'            => 'no',
                'method_title'       => __('Offline payment (EHZ)', 'ehz-wc-payment-fees'),
                'method_description' => __('Custom offline payment method provided by EHZ plugin.', 'ehz-wc-payment-fees'),
            ];
        }

        return $gateways;
    }

    /**
     * Dinamikus gateway osztály létrehozása az adott definícióhoz.
     */
    private function ensure_gateway_class(array $method): ?string
    {
        $id    = sanitize_title($method['id'] ?? $method['title'] ?? '');
        $title = trim((string) ($method['title'] ?? ''));

        if ($id === '' || $title === '') {
            return null;
        }

        $class_name = __NAMESPACE__ . '\\OfflineGateway_' . preg_replace('/[^A-Za-z0-9_]/', '_', strtoupper($id));

        if (! class_exists($class_name)) {
            class_alias(OfflineGateway::class, $class_name);
        }

        self::$gateway_configs[$class_name] = [
            'id'                 => $id,
            'title'              => $title,
            'description'        => (string) ($method['description'] ?? ''),
            'instructions'       => (string) ($method['instructions'] ?? ''),
            'enabled'            => (string) ($method['enabled'] ?? 'no'),
            'method_title'       => __('Offline payment (EHZ)', 'ehz-wc-payment-fees'),
            'method_description' => __('Custom offline payment method provided by EHZ plugin.', 'ehz-wc-payment-fees'),
        ];

        return $class_name;
    }

    /**
     * Admin menü: WooCommerce alatt egy "Payment fees" oldal.
     */
    public function register_admin_menu(): void
    {
        add_submenu_page(
            'woocommerce',
            __('Payment fees', 'ehz-wc-payment-fees'),
            __('Payment fees', 'ehz-wc-payment-fees'),
            'manage_woocommerce',
            'ehz-wc-payment-fees',
            [$this, 'render_admin_page']
        );
    }

    /**
     * WooCommerce Settings > Payments szekció bővítése.
     */
    public function add_checkout_section(array $sections): array
    {
        $sections['ehz_offline_methods'] = __('Offline payment methods (EHZ)', 'ehz-wc-payment-fees');

        return $sections;
    }

    /**
     * WooCommerce Settings > Payments > EHZ szekció mezőinek biztosítása.
     */
    public function add_checkout_settings(array $settings, string $current_section): array
    {
        if ($current_section !== 'ehz_offline_methods') {
            return $settings;
        }

        $settings = [];
        $settings[] = [
            'title' => __('Offline payment methods', 'ehz-wc-payment-fees'),
            'type'  => 'title',
            'desc'  => __('Create any number of offline payment gateways with custom labels and instructions.', 'ehz-wc-payment-fees'),
            'id'    => 'ehz_wc_offline_methods_title',
        ];

        $settings[] = [
            'type' => 'ehz_offline_methods',
            'id'   => self::OPTION_OFFLINE_METHODS,
        ];

        $settings[] = [
            'type' => 'sectionend',
            'id'   => 'ehz_wc_offline_methods_title',
        ];

        return $settings;
    }

    /**
     * Settings API regisztráció az option-hoz.
     */
    public function register_settings(): void
    {
        register_setting(
            'ehz_wc_payment_fees',
            self::OPTION_FEE_RULES,
            [
                'type'              => 'array',
                'sanitize_callback' => [self::class, 'sanitize_fee_rules'],
                'default'           => [],
            ]
        );

        register_setting(
            'ehz_wc_payment_fees',
            self::OPTION_SHIPPING_FILTERS,
            [
                'type'              => 'array',
                'sanitize_callback' => [self::class, 'sanitize_shipping_filters'],
                'default'           => [],
            ]
        );
    }

    /**
     * Sanitize / normalize az admin felületről érkező fee szabályokat.
     *
     * Vár: array[
     *   ['gateway_id' => 'cod', 'shipping_key' => 'flat_rate:3', 'fee' => '900'],
     *   ...
     * ]
     */
    public static function sanitize_fee_rules($value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $sanitized = [];

        foreach ($value as $row) {
            if (! is_array($row)) {
                continue;
            }

            $gateway_id   = isset($row['gateway_id']) ? sanitize_text_field((string) $row['gateway_id']) : '';
            $shipping_key = isset($row['shipping_key']) ? sanitize_text_field((string) $row['shipping_key']) : '';
            $fee_raw      = isset($row['fee']) ? wc_format_decimal($row['fee']) : '';

            if ($gateway_id === '' || $shipping_key === '' || $fee_raw === '') {
                continue;
            }

            $fee = (float) $fee_raw;
            if ($fee < 0) {
                $fee = 0.0;
            }

            $sanitized[] = [
                'gateway_id'   => $gateway_id,
                'shipping_key' => $shipping_key,
                'fee'          => $fee,
            ];
        }

        return $sanitized;
    }

    /**
     * Szállítási mód filter szabályainak szűrése.
     */
    public static function sanitize_shipping_filters($value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $sanitized = [];

        foreach ($value as $row) {
            if (! is_array($row)) {
                continue;
            }

            $gateway_id   = isset($row['gateway_id']) ? sanitize_text_field((string) $row['gateway_id']) : '';
            $shipping_key = isset($row['shipping_key']) ? sanitize_text_field((string) $row['shipping_key']) : '';

            if ($gateway_id === '' || $shipping_key === '') {
                continue;
            }

            $sanitized[] = [
                'gateway_id'   => $gateway_id,
                'shipping_key' => $shipping_key,
            ];
        }

        return $sanitized;
    }

    /**
     * Admin oldal renderelése.
     *
     * Itt tudsz hozzárendelni: (payment gateway) + (shipping method) -> fee.
     */
    public function render_admin_page(): void
    {
        if (! function_exists('\WC')) {
            return;
        }

        /** @var \WC_Payment_Gateways $pg */
        $pg = \WC()->payment_gateways();
        $gateways = $pg ? $pg->payment_gateways() : [];

        $shipping_method_options = $this->collect_shipping_method_options();
        $fee_rules = self::get_fee_rules();
        $shipping_filters = self::get_shipping_filters();
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('Payment fees by shipping method', 'ehz-wc-payment-fees'); ?></h1>

            <p>
                <?php esc_html_e('Define additional fees per payment method and shipping method combination.', 'ehz-wc-payment-fees'); ?>
            </p>

            <form method="post" action="options.php">
                <?php
                settings_fields('ehz_wc_payment_fees');
                ?>

                <h2><?php esc_html_e('Fee rules', 'ehz-wc-payment-fees'); ?></h2>
                <table class="widefat fixed wc_input_table" id="ehz-wc-fee-rules-table">
                    <thead>
                    <tr>
                        <th><?php esc_html_e('Payment method', 'ehz-wc-payment-fees'); ?></th>
                        <th><?php esc_html_e('Shipping method', 'ehz-wc-payment-fees'); ?></th>
                        <th><?php esc_html_e('Fee (gross)', 'ehz-wc-payment-fees'); ?></th>
                        <th><?php esc_html_e('Actions', 'ehz-wc-payment-fees'); ?></th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php
                    if (empty($fee_rules)) {
                        $fee_rules = [
                            [
                                'gateway_id'   => '',
                                'shipping_key' => '',
                                'fee'          => '',
                            ],
                        ];
                    }

                    foreach ($fee_rules as $index => $rule) :
                        $gateway_id   = isset($rule['gateway_id']) ? (string)$rule['gateway_id'] : '';
                        $shipping_key = isset($rule['shipping_key']) ? (string)$rule['shipping_key'] : '';
                        $fee          = isset($rule['fee']) ? (string)$rule['fee'] : '';
                        ?>
                        <tr class="ehz-wc-fee-rule-row">
                            <td>
                                <select name="ehz_wc_payment_fee_rules[<?php echo esc_attr((string)$index); ?>][gateway_id]">
                                    <option value=""><?php esc_html_e('Select…', 'ehz-wc-payment-fees'); ?></option>
                                    <?php foreach ($gateways as $id => $gateway) : ?>
                                        <option value="<?php echo esc_attr($id); ?>" <?php selected($id, $gateway_id); ?>>
                                            <?php echo esc_html($gateway->get_title() . ' (' . $id . ')'); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </td>
                            <td>
                                <select name="ehz_wc_payment_fee_rules[<?php echo esc_attr((string)$index); ?>][shipping_key]">
                                    <option value=""><?php esc_html_e('Select…', 'ehz-wc-payment-fees'); ?></option>
                                    <?php foreach ($shipping_method_options as $key => $label) : ?>
                                        <option value="<?php echo esc_attr($key); ?>" <?php selected($key, $shipping_key); ?>>
                                            <?php echo esc_html($label . ' [' . $key . ']'); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <p class="description">
                                    <?php
                                    esc_html_e('You can differentiate Packeta/FOXPOST etc. per method+instance.', 'ehz-wc-payment-fees');
                                    ?>
                                </p>
                            </td>
                            <td>
                                <input type="number"
                                       step="0.01"
                                       min="0"
                                       name="ehz_wc_payment_fee_rules[<?php echo esc_attr((string)$index); ?>][fee]"
                                       value="<?php echo esc_attr((string)$fee); ?>"
                                       style="width: 100px;"
                                /> Ft
                            </td>
                            <td>
                                <button type="button" class="button ehz-wc-remove-row">
                                    <?php esc_html_e('Remove', 'ehz-wc-payment-fees'); ?>
                                </button>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>

                <p>
                    <button type="button" class="button button-secondary" data-add-row="ehz-wc-fee-rules-table">
                        <?php esc_html_e('Add rule', 'ehz-wc-payment-fees'); ?>
                    </button>
                </p>

                <h2><?php esc_html_e('Payment method availability by shipping', 'ehz-wc-payment-fees'); ?></h2>
                <p class="description">
                    <?php esc_html_e('Restrict which payment methods appear for specific shipping services.', 'ehz-wc-payment-fees'); ?>
                </p>
                <table class="widefat fixed wc_input_table" id="ehz-wc-shipping-filters-table">
                    <thead>
                    <tr>
                        <th><?php esc_html_e('Payment method', 'ehz-wc-payment-fees'); ?></th>
                        <th><?php esc_html_e('Shipping method', 'ehz-wc-payment-fees'); ?></th>
                        <th><?php esc_html_e('Actions', 'ehz-wc-payment-fees'); ?></th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php
                    if (empty($shipping_filters)) {
                        $shipping_filters = [
                            [
                                'gateway_id'   => '',
                                'shipping_key' => '',
                            ],
                        ];
                    }

                    foreach ($shipping_filters as $index => $rule) :
                        $gateway_id   = isset($rule['gateway_id']) ? (string)$rule['gateway_id'] : '';
                        $shipping_key = isset($rule['shipping_key']) ? (string)$rule['shipping_key'] : '';
                        ?>
                        <tr class="ehz-wc-shipping-filter-row">
                            <td>
                                <select name="<?php echo esc_attr(self::OPTION_SHIPPING_FILTERS); ?>[<?php echo esc_attr((string)$index); ?>][gateway_id]">
                                    <option value=""><?php esc_html_e('Select…', 'ehz-wc-payment-fees'); ?></option>
                                    <?php foreach ($gateways as $id => $gateway) : ?>
                                        <option value="<?php echo esc_attr($id); ?>" <?php selected($id, $gateway_id); ?>>
                                            <?php echo esc_html($gateway->get_title() . ' (' . $id . ')'); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </td>
                            <td>
                                <select name="<?php echo esc_attr(self::OPTION_SHIPPING_FILTERS); ?>[<?php echo esc_attr((string)$index); ?>][shipping_key]">
                                    <option value=""><?php esc_html_e('Select…', 'ehz-wc-payment-fees'); ?></option>
                                    <?php foreach ($shipping_method_options as $key => $label) : ?>
                                        <option value="<?php echo esc_attr($key); ?>" <?php selected($key, $shipping_key); ?>>
                                            <?php echo esc_html($label . ' [' . $key . ']'); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </td>
                            <td>
                                <button type="button" class="button ehz-wc-remove-row">
                                    <?php esc_html_e('Remove', 'ehz-wc-payment-fees'); ?>
                                </button>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>

                <p>
                    <button type="button" class="button button-secondary" data-add-row="ehz-wc-shipping-filters-table">
                        <?php esc_html_e('Add restriction', 'ehz-wc-payment-fees'); ?>
                    </button>
                </p>

                <?php submit_button(); ?>
            </form>
        </div>
        <script>
            (function () {
                const initTable = (tableId, rowClass) => {
                    const tableBody = document.querySelector(`#${tableId} tbody`);
                    if (!tableBody) {
                        return;
                    }

                    tableBody.addEventListener('click', function (e) {
                        const target = e.target;
                        if (target && target.classList.contains('ehz-wc-remove-row')) {
                            const row = target.closest('tr.' + rowClass);
                            if (row && tableBody.querySelectorAll('tr.' + rowClass).length > 1) {
                                row.remove();
                            }
                        }
                    });
                };

                const addButtons = document.querySelectorAll('[data-add-row]');
                addButtons.forEach(function (btn) {
                    btn.addEventListener('click', function () {
                        const targetId = btn.getAttribute('data-add-row');
                        const tableBody = document.querySelector('#' + targetId + ' tbody');
                        if (!tableBody) {
                            return;
                        }

                        const rows = tableBody.querySelectorAll('tr');
                        if (!rows.length) {
                            return;
                        }

                        const template = rows[0].cloneNode(true);
                        const newIndex = rows.length;

                        template.querySelectorAll('select, input').forEach(function (el) {
                            el.value = '';
                            const name = el.getAttribute('name');
                            if (name) {
                                el.setAttribute('name', name.replace(/\[\d+]/, '[' + newIndex + ']'));
                            }
                        });

                        tableBody.appendChild(template);
                    });
                });

                initTable('ehz-wc-fee-rules-table', 'ehz-wc-fee-rule-row');
                initTable('ehz-wc-shipping-filters-table', 'ehz-wc-shipping-filter-row');
            })();
        </script>
        <?php
    }

    /**
     * Offline fizetési módok mező kirajzolása WooCommerce Settings alatt.
     */
    public function render_offline_methods_field(): void
    {
        $methods = self::get_offline_methods();

        if (empty($methods)) {
            $methods = [
                [
                    'id'           => '',
                    'title'        => '',
                    'description'  => '',
                    'instructions' => '',
                    'enabled'      => 'yes',
                ],
            ];
        }

        ?>
        <table class="widefat fixed wc_gateways wc_input_table" id="ehz-wc-offline-methods-table">
            <thead>
            <tr>
                <th><?php esc_html_e('Identifier', 'ehz-wc-payment-fees'); ?></th>
                <th><?php esc_html_e('Title', 'ehz-wc-payment-fees'); ?></th>
                <th><?php esc_html_e('Description', 'ehz-wc-payment-fees'); ?></th>
                <th><?php esc_html_e('Instructions', 'ehz-wc-payment-fees'); ?></th>
                <th><?php esc_html_e('Enabled', 'ehz-wc-payment-fees'); ?></th>
                <th><?php esc_html_e('Actions', 'ehz-wc-payment-fees'); ?></th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($methods as $index => $method) :
                $id           = isset($method['id']) ? (string) $method['id'] : '';
                $title        = isset($method['title']) ? (string) $method['title'] : '';
                $description  = isset($method['description']) ? (string) $method['description'] : '';
                $instructions = isset($method['instructions']) ? (string) $method['instructions'] : '';
                $enabled      = isset($method['enabled']) ? (string) $method['enabled'] : 'yes';
                ?>
                <tr class="ehz-wc-offline-row">
                    <td>
                        <input type="text" name="<?php echo esc_attr(self::OPTION_OFFLINE_METHODS); ?>[<?php echo esc_attr((string)$index); ?>][id]" value="<?php echo esc_attr($id); ?>" placeholder="offline_cash" />
                        <p class="description"><?php esc_html_e('Unique slug (characters, numbers, dash).', 'ehz-wc-payment-fees'); ?></p>
                    </td>
                    <td>
                        <input type="text" name="<?php echo esc_attr(self::OPTION_OFFLINE_METHODS); ?>[<?php echo esc_attr((string)$index); ?>][title]" value="<?php echo esc_attr($title); ?>" />
                    </td>
                    <td>
                        <textarea name="<?php echo esc_attr(self::OPTION_OFFLINE_METHODS); ?>[<?php echo esc_attr((string)$index); ?>][description]" rows="2" cols="20"><?php echo esc_textarea($description); ?></textarea>
                    </td>
                    <td>
                        <textarea name="<?php echo esc_attr(self::OPTION_OFFLINE_METHODS); ?>[<?php echo esc_attr((string)$index); ?>][instructions]" rows="2" cols="20"><?php echo esc_textarea($instructions); ?></textarea>
                        <p class="description"><?php esc_html_e('Shown on thank you page and emails.', 'ehz-wc-payment-fees'); ?></p>
                    </td>
                    <td>
                        <label>
                            <input type="checkbox" value="yes" name="<?php echo esc_attr(self::OPTION_OFFLINE_METHODS); ?>[<?php echo esc_attr((string)$index); ?>][enabled]" <?php checked($enabled, 'yes'); ?> />
                            <?php esc_html_e('Enabled', 'ehz-wc-payment-fees'); ?>
                        </label>
                    </td>
                    <td>
                        <button type="button" class="button ehz-wc-remove-row"><?php esc_html_e('Remove', 'ehz-wc-payment-fees'); ?></button>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <p>
            <button type="button" class="button button-secondary" data-add-row="ehz-wc-offline-methods-table"><?php esc_html_e('Add payment method', 'ehz-wc-payment-fees'); ?></button>
        </p>

        <script>
            (function () {
                const table = document.querySelector('#ehz-wc-offline-methods-table tbody');
                const addBtn = document.querySelector('[data-add-row="ehz-wc-offline-methods-table"]');

                if (!table || !addBtn) {
                    return;
                }

                addBtn.addEventListener('click', function () {
                    const rows = table.querySelectorAll('tr');
                    if (!rows.length) {
                        return;
                    }

                    const template = rows[0].cloneNode(true);
                    const newIndex = rows.length;

                    template.querySelectorAll('input, textarea').forEach(function (el) {
                        const name = el.getAttribute('name');
                        if (name) {
                            el.setAttribute('name', name.replace(/\[\d+]/, '[' + newIndex + ']'));
                        }
                        if (el.type === 'checkbox') {
                            el.checked = true;
                        } else {
                            el.value = '';
                        }
                    });

                    table.appendChild(template);
                });

                table.addEventListener('click', function (e) {
                    const target = e.target;
                    if (target && target.classList.contains('ehz-wc-remove-row')) {
                        const rows = table.querySelectorAll('tr');
                        if (rows.length > 1) {
                            target.closest('tr')?.remove();
                        }
                    }
                });
            })();
        </script>
        <?php
    }

    /**
     * WooCommerce settings mentésének kezelése az egyedi mezőhöz.
     */
    public function handle_checkout_settings_save(): void
    {
        $current_section = isset($_GET['section']) ? sanitize_text_field((string) $_GET['section']) : '';
        if ($current_section !== 'ehz_offline_methods') {
            return;
        }

        $raw_methods = $_POST[self::OPTION_OFFLINE_METHODS] ?? [];
        update_option(self::OPTION_OFFLINE_METHODS, $this->sanitize_offline_methods($raw_methods));
    }

    /**
     * Offline fizetési módok tisztítása/normalizálása.
     */
    private function sanitize_offline_methods($methods): array
    {
        if (! is_array($methods)) {
            return [];
        }

        $sanitized = [];
        foreach ($methods as $method) {
            if (! is_array($method)) {
                continue;
            }

            $id    = sanitize_title($method['id'] ?? $method['title'] ?? '');
            $title = trim((string) ($method['title'] ?? ''));

            if ($id === '' || $title === '') {
                continue;
            }

            $sanitized[] = [
                'id'           => $id,
                'title'        => $title,
                'description'  => sanitize_textarea_field((string) ($method['description'] ?? '')),
                'instructions' => sanitize_textarea_field((string) ($method['instructions'] ?? '')),
                'enabled'      => (isset($method['enabled']) && $method['enabled'] === 'yes') ? 'yes' : 'no',
            ];
        }

        return $sanitized;
    }

    /**
     * Összegyűjti az elérhető szállítási módokat (method_id:instance_id) => label formában.
     * Ez alapján lehet Packeta / Foxpost / stb. szeparálni.
     */
    private function collect_shipping_method_options(): array
    {
        $options = [];

        // Zónák szerinti módszerek
        $zones = \WC_Shipping_Zones::get_zones();
        foreach ($zones as $zone) {
            $zone_name = $zone['zone_name'] ?? __('Zone', 'ehz-wc-payment-fees');
            /** @var \WC_Shipping_Method[] $methods */
            $methods = $zone['shipping_methods'] ?? [];
            foreach ($methods as $method) {
                $key   = $method->id . ':' . $method->instance_id;
                $title = $method->get_title();
                if ($title === '') {
                    $title = $method->get_method_title();
                }

                $label = sprintf('%s – %s', $zone_name, $title);
                $options[$key] = $label;
            }
        }

        // "Rest of the world" zóna
        $default_zone = new \WC_Shipping_Zone(0);
        $default_methods = $default_zone->get_shipping_methods();
        foreach ($default_methods as $method) {
            $key   = $method->id . ':' . $method->instance_id;
            $title = $method->get_title();
            if ($title === '') {
                $title = $method->get_method_title();
            }

            $label = sprintf('%s – %s', __('Rest of the world', 'ehz-wc-payment-fees'), $title);
            $options[$key] = $label;
        }

        // Ha nagyon nincs semmi, fallback
        if (empty($options)) {
            $options['flat_rate'] = 'flat_rate (fallback, method_id only)';
        }

        ksort($options);

        return $options;
    }

    /**
     * Option getter.
     */
    public static function get_fee_rules(): array
    {
        $rules = get_option(self::OPTION_FEE_RULES, []);
        return is_array($rules) ? $rules : [];
    }

    /**
     * Option getter a szállítási filterekhez.
     */
    public static function get_shipping_filters(): array
    {
        $rules = get_option(self::OPTION_SHIPPING_FILTERS, []);
        return is_array($rules) ? $rules : [];
    }

    /**
     * Offline metódusok lekérdezése.
     */
    public static function get_offline_methods(): array
    {
        $methods = get_option(self::OPTION_OFFLINE_METHODS, []);
        if (! is_array($methods)) {
            return [];
        }

        $normalized = [];
        foreach ($methods as $method) {
            if (! is_array($method)) {
                continue;
            }

            $id    = sanitize_title($method['id'] ?? $method['title'] ?? '');
            $title = trim((string) ($method['title'] ?? ''));

            if ($id === '' || $title === '') {
                continue;
            }

            $normalized[] = [
                'id'           => $id,
                'title'        => $title,
                'description'  => (string) ($method['description'] ?? ''),
                'instructions' => (string) ($method['instructions'] ?? ''),
                'enabled'      => (string) ($method['enabled'] ?? 'no'),
            ];
        }

        return $normalized;
    }

    /**
     * Kiszámolja az aktuális kosárra vonatkozó díjat a kiválasztott fizetési mód + szállítás alapján.
     *
     * Ezt egy másik plugin is használhatja: ehz_wc_get_payment_fee_for_cart()
     */
    public static function get_fee_for_current_cart(?string $gateway_id = null): float
    {
        if (! function_exists('\WC')) {
            return 0.0;
        }

        if ($gateway_id === null) {
            $gateway_id = (string) \WC()->session->get('chosen_payment_method', '');
        }

        if ($gateway_id === '') {
            return 0.0;
        }

        $chosen_shipping = (array) \WC()->session->get('chosen_shipping_methods', []);
        if (empty($chosen_shipping)) {
            return 0.0;
        }

        // Egyszerűsítés: első shipping package
        $shipping_key = (string) reset($chosen_shipping);

        return self::get_fee_for($gateway_id, $shipping_key);
    }

    /**
     * Kiszámítja a díjat egy adott fizetési mód + szállítási kombinációra.
     *
     * Itt érvényesül a filter alapú API is.
     */
    public static function get_fee_for(string $gateway_id, string $shipping_key, array $context = []): float
    {
        $rules = self::get_fee_rules();

        $fee = 0.0;
        $shipping_id_only = $shipping_key;
        if (str_contains($shipping_key, ':')) {
            $parts = explode(':', $shipping_key);
            $shipping_id_only = $parts[0];
        }

        foreach ($rules as $rule) {
            if (! is_array($rule)) {
                continue;
            }

            $r_gateway = (string) ($rule['gateway_id'] ?? '');
            $r_ship    = (string) ($rule['shipping_key'] ?? '');
            $r_fee     = (float) ($rule['fee'] ?? 0.0);

            if ($r_gateway !== $gateway_id) {
                continue;
            }

            // Első kör: teljes kulcs egyezés (method_id:instance_id)
            if ($r_ship === $shipping_key) {
                $fee += $r_fee;
                continue;
            }

            // Második kör: csak method_id egyezés (pl. minden flat_rate instance)
            if ($r_ship === $shipping_id_only) {
                $fee += $r_fee;
            }
        }

        // Filter alapú "szerződés": más plugin is beleszólhat
        $fee = (float) apply_filters(
            'ehz_wc_payment_method_fee',
            $fee,
            $gateway_id,
            $shipping_key,
            $context
        );

        if ($fee < 0) {
            $fee = 0.0;
        }

        return $fee;
    }

    /**
     * Fizetési módok kiszűrése szállítási metódus alapján.
     */
    public function filter_gateways_by_shipping(array $available_gateways): array
    {
        if (is_admin()) {
            return $available_gateways;
        }

        $chosen_shipping = \WC()->session->get('chosen_shipping_methods', []);
        $shipping_key = (string) ($chosen_shipping[0] ?? '');

        if ($shipping_key === '') {
            return $available_gateways;
        }

        $shipping_id_only = str_contains($shipping_key, ':')
            ? explode(':', $shipping_key)[0]
            : $shipping_key;

        foreach (self::get_shipping_filters() as $rule) {
            $gateway_id = (string) ($rule['gateway_id'] ?? '');
            $ship_rule  = (string) ($rule['shipping_key'] ?? '');

            if ($gateway_id === '' || $ship_rule === '') {
                continue;
            }

            $match = $ship_rule === $shipping_key || $ship_rule === $shipping_id_only;
            if ($match && isset($available_gateways[$gateway_id])) {
                unset($available_gateways[$gateway_id]);
            }
        }

        return $available_gateways;
    }

    /**
     * Díj rátétele a kosárra.
     */
    public function add_fee_to_cart(\WC_Cart $cart): void
    {
        if (is_admin() && ! defined('DOING_AJAX')) {
            return;
        }

        if (! function_exists('\WC')) {
            return;
        }

        $gateway_id = (string) \WC()->session->get('chosen_payment_method', '');
        if ($gateway_id === '') {
            return;
        }

        $fee = self::get_fee_for_current_cart($gateway_id);
        if ($fee <= 0) {
            return;
        }

        // Gateway objektum a labelhez
        $gateways = \WC()->payment_gateways()->payment_gateways();
        $label = __('Payment fee', 'ehz-wc-payment-fees');
        if (isset($gateways[$gateway_id])) {
            $label = sprintf(
                __('%s fee', 'ehz-wc-payment-fees'),
                $gateways[$gateway_id]->get_title()
            );
        }

        $taxable = true;   // ha kell, itt tudod állítani
        $tax_class = '';   // WooCommerce eldönti

        $cart->add_fee($label, $fee, $taxable, $tax_class);
    }

    /**
     * Checkout fizetési mód címének kiegészítése a díjjal (pl. "Utánvét (+900 Ft)").
     */
    public function append_fee_to_gateway_title(string $title, string $gateway_id): string
    {
        if (! is_checkout() && ! is_checkout_pay_page()) {
            return $title;
        }

        $fee = self::get_fee_for_current_cart($gateway_id);
        if ($fee <= 0) {
            return $title;
        }

        return sprintf('%s (+%s)', $title, wc_price($fee));
    }
}

/**
 * Nyilvános helper függvény más pluginek számára:
 *
 * ehz_wc_get_payment_fee_for_cart( $gateway_id = null ): float
 */
function ehz_wc_get_payment_fee_for_cart(?string $gateway_id = null): float
{
    if (! is_woocommerce_active()) {
        return 0.0;
    }

    return Plugin::get_fee_for_current_cart($gateway_id);
}
