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

    Plugin::instance();
}

/**
 * Fő plugin osztály (singleton)
 */
final class Plugin
{
    private static ?self $instance = null;

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
        // Új offline fizetési mód
        add_filter('woocommerce_payment_gateways', [$this, 'register_offline_gateway']);

        // Admin: beállítás oldal
        add_action('admin_menu', [$this, 'register_admin_menu']);
        add_action('admin_init', [$this, 'register_settings']);

        // Díj hozzáadása a kosárhoz
        add_action('woocommerce_cart_calculate_fees', [$this, 'add_fee_to_cart']);

        // Fizetési mód címének kiegészítése a díjjal
        add_filter('woocommerce_gateway_title', [$this, 'append_fee_to_gateway_title'], 10, 2);
    }

    /**
     * Új offline fizetési mód regisztrálása
     */
    public function register_offline_gateway(array $gateways): array
    {
        $gateways[] = OfflineGateway::class;
        return $gateways;
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
     * Settings API regisztráció az option-hoz.
     */
    public function register_settings(): void
    {
        register_setting(
            'ehz_wc_payment_fees',
            'ehz_wc_payment_fee_rules',
            [
                'type'              => 'array',
                'sanitize_callback' => [self::class, 'sanitize_fee_rules'],
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

                <table class="widefat fixed" id="ehz-wc-fee-rules-table">
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
                    <button type="button" class="button button-secondary" id="ehz-wc-add-row">
                        <?php esc_html_e('Add rule', 'ehz-wc-payment-fees'); ?>
                    </button>
                </p>

                <?php submit_button(); ?>
            </form>
        </div>
        <script>
            (function () {
                const tableBody = document.querySelector('#ehz-wc-fee-rules-table tbody');
                const addBtn    = document.getElementById('ehz-wc-add-row');

                if (!tableBody || !addBtn) {
                    return;
                }

                addBtn.addEventListener('click', function () {
                    const rows = tableBody.querySelectorAll('tr.ehz-wc-fee-rule-row');
                    const lastIndex = rows.length ? rows[rows.length - 1].rowIndex : 0;
                    const newIndex = rows.length;

                    const template = rows[0].cloneNode(true);
                    // Reset values
                    template.querySelectorAll('select, input').forEach(function (el) {
                        el.value = '';
                        const name = el.getAttribute('name');
                        if (name) {
                            el.setAttribute('name', name.replace(/\[\d+]/, '[' + newIndex + ']'));
                        }
                    });

                    tableBody.appendChild(template);
                });

                tableBody.addEventListener('click', function (e) {
                    const target = e.target;
                    if (target && target.classList.contains('ehz-wc-remove-row')) {
                        const row = target.closest('tr.ehz-wc-fee-rule-row');
                        if (row && tableBody.querySelectorAll('tr.ehz-wc-fee-rule-row').length > 1) {
                            row.remove();
                        }
                    }
                });
            })();
        </script>
        <?php
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
                $label = sprintf('%s – %s', $zone_name, $method->get_method_title());
                $options[$key] = $label;
            }
        }

        // "Rest of the world" zóna
        $default_zone = new \WC_Shipping_Zone(0);
        $default_methods = $default_zone->get_shipping_methods();
        foreach ($default_methods as $method) {
            $key   = $method->id . ':' . $method->instance_id;
            $label = sprintf('%s – %s', __('Rest of the world', 'ehz-wc-payment-fees'), $method->get_method_title());
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
        $rules = get_option('ehz_wc_payment_fee_rules', []);
        return is_array($rules) ? $rules : [];
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
 * Offline fizetési mód (ehz_wc_offline_cash)
 */
final class OfflineGateway extends \WC_Payment_Gateway
{
    public function __construct()
    {
        $this->id                 = 'ehz_wc_offline_cash';
        $this->method_title       = __('Offline payment (EHZ)', 'ehz-wc-payment-fees');
        $this->method_description = __('Custom offline payment method provided by EHZ plugin.', 'ehz-wc-payment-fees');
        $this->has_fields         = false;

        // Load the settings.
        $this->init_form_fields();
        $this->init_settings();

        $this->title        = $this->get_option('title', __('Offline payment', 'ehz-wc-payment-fees'));
        $this->description  = $this->get_option('description', '');
        $this->enabled      = $this->get_option('enabled', 'no');

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
        ];
    }

    public function process_payment($order_id): array
    {
        $order = wc_get_order($order_id);

        // Offline: a rendelést "on-hold" vagy "processing" státuszra lehet tenni, igény szerint.
        $order->update_status('on-hold', __('Awaiting offline payment', 'ehz-wc-payment-fees'));

        // Kosár ürítése
        \WC()->cart->empty_cart();

        return [
            'result'   => 'success',
            'redirect' => $this->get_return_url($order),
        ];
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
