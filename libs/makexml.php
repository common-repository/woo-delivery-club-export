<?php

class WooDeliveryClubExport
{
    private $settings = null;
    private $product = null;
    private $offer = null;

    function __construct() {
        $this->settings = get_option('market_exporter_shop_settings');

        if (!isset( $this->settings['image_count'])) {
            $this->settings['image_count'] = 10;
        }
    }

    /**
     * @return mixed[]
     */
    public function generateData() 
    {
        if (!$currency = $this->checkCurrency()) {
            return 100;
        }

        if (!$query = $this->checkProducts()) {
            return 300;
        }

        $data[0] = $this->getCategories();
        $data[1] = $this->getProducts($currency, $query);

        delete_option('market_exporter_doing_cron');

        return $data;
    }

    /**
     * @return string|bool
     */
    private function checkProducts() 
    {
        $args = array(
            'posts_per_page' => -1,
            'post_type' => array( 'product' ),
            'post_status' => 'publish',
            'meta_query' => array(
                array(
                    'key' => '_price',
                    'value' => 0,
                    'compare' => '>',
                    'type' => 'NUMERIC',
                ),
                array(
                    'key' => '_stock_status',
                    'value' => 'instock',
                ),
            ),
            'orderby' => 'ID',
            'order' => 'DESC',
        );

        if (isset($this->settings['backorders']) && true === $this->settings['backorders']) {
            array_pop($args['meta_query']);

            $args['meta_query'][] = array(
                'relation' => 'OR',
                array(
                    'key' => '_stock_status',
                    'value' => 'instock',
                ),
                array(
                    'key' => '_backorders',
                    'value' => 'yes',
                ),
            );
        }

        if (isset($this->settings['include_cat']) && !empty($this->settings['include_cat'])) {
            $args['tax_query'] = array(
                array(
                    'taxonomy' => 'product_cat',
                    'field' => 'term_id',
                    'terms' => $this->settings['include_cat'],
                ),
            );
        }

        $query = new WP_Query($args);

        if (0 !== $query->found_posts) {
            return $query;
        }

        return false;
    }

    /**
     * @return string|bool
     */
    private function checkCurrency() 
    {
        $currency = get_woocommerce_currency();
        switch ( $currency ) {
            case 'RUB':
            return 'RUR';
            case 'BYR':
            return 'BYN';
            case 'UAH':
            case 'BYN':
            case 'USD':
            case 'EUR':
            case 'KZT':
            return $currency;
            default:
            return false;
        }
    }

    /**
     * @return mixed[]
     */
    private function getCategories()
    {
        $args = array(
            'taxonomy' => 'product_cat',
            'orderby' => 'term_id',
        );

        $categories = array();

        foreach (get_categories($args) as $category) {
            if (0 === $category->parent) {
                $categories[$category->cat_ID] = array(wp_strip_all_tags($category->name), null, -1);
            } else {
                $categories[$category->cat_ID] = array(wp_strip_all_tags($category->name), null, $category->parent);
            }
        }

        return $categories;
    }


    /**
     * @param string$currency
     * @param WP_Query $query
     * @return string
     */
    private function getProducts( $currency, WP_Query $query ) {
        $products = array();

        while ($query->have_posts()) {
            $query->the_post();
            $this->product = wc_get_product( $query->post->ID );
            $this->offer = $this->product;
            $variations = array();
            $variation_count = 1;

            if ($this->product->is_type('variable')) {
                $variations = $this->product->get_available_variations();
                $variation_count = count($variations);
            }

            while ($variation_count > 0) {
                $variation_count--;
                $this->offer_id = (($this->product->is_type( 'variable' )) ? $variations[$variation_count]['variation_id'] : $this->product->get_id());

                if ($this->product->is_type( 'variable' )) {
                    $this->offer = new WC_Product_Variation($this->offer_id);
                }

                $type_prefix_set = false;
                if (isset( $this->settings['type_prefix']) && 'not_set' !== $this->settings['type_prefix']) {
                    $type_prefix = $this->product->get_attribute('pa_' . $this->settings['type_prefix']);

                    if ($type_prefix) {
                        $type_prefix_set = true;
                    }
                }

                if ($this->offer->get_sale_price() && ($this->offer->get_sale_price() < $this->offer->get_regular_price())) {
                    $price = $this->offer->get_sale_price();
                } else {
                    $price = $this->offer->get_regular_price();
                }

                $image = get_the_post_thumbnail_url($this->offer->get_id(), 'full');

                if (!$image) {
                    $image = get_the_post_thumbnail_url($this->product->get_id(), 'full');
                }

                if (strlen(utf8_decode($image)) <= 512) {
                    $image = esc_url($image);
                }

                if (isset($this->settings['size']) && $this->settings['size']) {
                    echo get_option('woocommerce_weight_unit');
                    $sizeUnit = esc_attr(get_option('woocommerce_weight_unit'));

                    if ($this->product->has_weight() && 'kg' === $sizeUnit) {
                        $weight = $this->product->get_weight();
                    }

                    $sizeUnit = esc_attr(get_option('woocommerce_dimension_unit'));

                    if ($this->product->has_dimensions() && 'cm' === $sizeUnit ) {
                        if (self::wooLatestVersions()) {
                            $dimensions = implode( '/', $this->product->get_dimensions( false ) );
                        }
                    }
                }

                $categories = get_the_terms($this->product->get_id(), 'product_cat');
                $categoryId = null;

                if ($categories) {
                    $category = array_shift($categories);
                    $categoryId = $category->term_id;
                }

                $titleProduct = trim(preg_replace('/\((.*)\)/', '', strip_tags($this->offer->get_formatted_name())));

                $products[$this->offer_id] = array(
                    $categoryId,
                    $titleProduct . ' ' . $dimensions,
                    $this->getDescription(),
                    $price,
                    $image,
                    'weight' => $this->offer->get_weight(),
                );
            }
        }

        return $products;
    }

    /**
     * @param string $version
     * @return string
     */
    private function getDescription($type = 'default') 
    {
        switch ( $type ) {
            case 'default': 
                if (self::wooLatestVersions()) {
                    $description = $this->offer->get_Description();

                    if (empty( $description )) {
                        $description = $this->product->get_Description();
                    }

                } else {
                    if ($this->product->is_type( 'variable' ) && ! $this->offer->get_variation_description()) {
                        $description = $this->offer->get_variation_description();
                    } else {
                        $description = $this->offer->post->post_content;
                    }
                }

                break;
            case 'long':
                if (self::wooLatestVersions()) {
                    $description = $this->product->get_Description();
                } else {
                    $description = $this->offer->post->post_content;
                }

                break;
            case 'short':
                if (self::wooLatestVersions()) {
                    $description = $this->offer->get_short_description();
                } else {
                    $description = $this->offer->post->post_excerpt;
                }

                break;
        }

        if (empty($description)) {
           if (self::wooLatestVersions()) {
                $description = $this->offer->get_short_description();
            } else {
                $description = $this->offer->post->post_excerpt;
            }
        } else {
            if (self::wooLatestVersions()) {
                $description .= '||' . $this->offer->get_short_description();
            } else {
                $description .= '||' . $this->offer->post->post_excerpt;
            }
        }

        $description = strip_tags(strip_shortcodes($description), '<h3><ul><li><p>');
        $description = html_entity_decode($description, ENT_COMPAT, 'UTF-8');

        return $description;
    }

    /**
     * @param string $version
     * @return bool
     */
    private static function wooLatestVersions($version = '3.0.0') 
    {
        if (!function_exists( 'get_plugins' )) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        $woo_installed = get_plugins( '/woocommerce' );
        $woo_version = $woo_installed['woocommerce.php']['Version'];

        if (version_compare( $woo_version, $version, '>=' )) {
            return true;
        }

        return false;
    }
}