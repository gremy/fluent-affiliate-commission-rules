<?php
declare(strict_types=1);

namespace FACommissionRules\Admin;

defined( 'ABSPATH' ) || exit;

use FACommissionRules\Fluent;
use FACommissionRules\Resolver;
use FACommissionRules\Store;

/**
 * Add/edit form and the three admin-post handlers. Field order is audience,
 * then target, then money, then time, then the note that saves you a year later.
 *
 * @package FACommissionRules
 */
final class RuleForm {
  public function register(): void {
    add_action( 'admin_post_facr_save_rule', [ $this, 'handle_save' ] );
    add_action( 'admin_post_facr_delete_rule', [ $this, 'handle_delete' ] );
    add_action( 'admin_post_facr_bulk', [ $this, 'handle_bulk' ] );
  }

  // ------------------------------------------------------------ handlers ---

  public function handle_save(): void {
    self::guard( 'facr_save_rule' );

    $target_type = isset( $_POST['facr_target_type'] ) ? sanitize_key( wp_unslash( $_POST['facr_target_type'] ) ) : 'all';

    // The category picker and the product picker are both on the page, and a
    // disabled select still posts in some browsers. Read only the control the
    // chosen target type owns, so a stale picker can never smuggle ids into the
    // saved rule — and an "all products" rule reads no ids at all.
    $ids_field  = 'facr_target_ids_' . $target_type;
    $target_ids = isset( $_POST[ $ids_field ] ) ? array_map( 'intval', (array) wp_unslash( $_POST[ $ids_field ] ) ) : [];

    $input = [
      'id'          => isset( $_POST['facr_id'] ) ? sanitize_text_field( wp_unslash( $_POST['facr_id'] ) ) : '',
      'created_at'  => isset( $_POST['facr_created_at'] ) ? sanitize_text_field( wp_unslash( $_POST['facr_created_at'] ) ) : '',
      'status'      => isset( $_POST['facr_status'] ) ? sanitize_key( wp_unslash( $_POST['facr_status'] ) ) : 'active',
      'scope_type'  => isset( $_POST['facr_scope_type'] ) ? sanitize_key( wp_unslash( $_POST['facr_scope_type'] ) ) : 'all',
      'scope_id'    => isset( $_POST['facr_scope_id'] ) ? (int) $_POST['facr_scope_id'] : 0,
      'target_type' => $target_type,
      'target_ids'  => $target_ids,
      'rate'        => isset( $_POST['facr_rate'] ) ? sanitize_text_field( wp_unslash( $_POST['facr_rate'] ) ) : '',
      'rate_type'   => isset( $_POST['facr_rate_type'] ) ? sanitize_key( wp_unslash( $_POST['facr_rate_type'] ) ) : 'percentage',
      'starts_at'   => isset( $_POST['facr_starts_at'] ) ? sanitize_text_field( wp_unslash( $_POST['facr_starts_at'] ) ) : '',
      'ends_at'     => isset( $_POST['facr_ends_at'] ) ? sanitize_text_field( wp_unslash( $_POST['facr_ends_at'] ) ) : '',
      'note'        => isset( $_POST['facr_note'] ) ? sanitize_text_field( wp_unslash( $_POST['facr_note'] ) ) : '',
    ];

    [ $rule, $errors ] = Store::validate( $input );

    if ( $errors ) {
      set_transient( self::error_key(), [ 'errors' => $errors, 'input' => $rule ], 60 );
      wp_safe_redirect(
        Menu::page_url(
          [
            'action' => $input['id'] !== '' ? 'edit' : 'add',
            'rule'   => (string) $input['id'],
          ]
        )
      );
      exit;
    }

    Store::save( $rule );

    // Non-blocking: the rule is saved either way, but an equally specific rival
    // means the only thing deciding real money is which one is newer.
    $ties     = Resolver::tie_map( array_merge( Store::all(), Store::fluent_global_rules() ) );
    $conflict = ! empty( $ties[ (string) $rule['id'] ] );

    wp_safe_redirect(
      Menu::page_url(
        [
          'facr_notice' => $conflict ? 'saved_conflict' : 'saved',
          'facr_detail' => RulesPage::scope_label( $rule ) . ' — ' . RulesPage::describe( $rule ),
        ]
      )
    );
    exit;
  }

  public function handle_delete(): void {
    // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- the nonce is checked inside guard(), which needs this value to build the action.
    $id = isset( $_GET['rule'] ) ? sanitize_text_field( wp_unslash( $_GET['rule'] ) ) : '';
    self::guard( 'facr_delete_rule_' . $id );
    Store::delete( $id );
    wp_safe_redirect( Menu::page_url( [ 'facr_notice' => 'deleted' ] ) );
    exit;
  }

  public function handle_bulk(): void {
    self::guard( 'facr_bulk' );

    $bulk = isset( $_POST['facr_bulk_action'] ) ? sanitize_key( wp_unslash( $_POST['facr_bulk_action'] ) ) : '';
    $ids  = isset( $_POST['facr_ids'] ) ? array_map( 'sanitize_text_field', (array) wp_unslash( $_POST['facr_ids'] ) ) : [];

    $count = 0;
    if ( $ids ) {
      if ( $bulk === 'activate' ) {
        $count = Store::set_status( $ids, 'active' );
      } elseif ( $bulk === 'deactivate' ) {
        $count = Store::set_status( $ids, 'inactive' );
      } elseif ( $bulk === 'delete' ) {
        $count = Store::delete_many( $ids );
      }
    }

    wp_safe_redirect(
      Menu::page_url(
        [
          'facr_notice' => 'bulk',
          'facr_detail' => sprintf(
            /* translators: %d: number of rules changed */
            _n( '%d rule updated.', '%d rules updated.', $count, 'fa-commission-rules' ),
            $count
          ),
        ]
      )
    );
    exit;
  }

  /** Permission first, then the nonce. check_admin_referer() covers GET and POST alike. */
  private static function guard( string $nonce_action ): void {
    if ( ! Fluent::can_manage() ) {
      wp_die( esc_html__( 'You do not have permission to manage commission rules.', 'fa-commission-rules' ), '', [ 'response' => 403 ] );
    }
    check_admin_referer( $nonce_action );
  }

  private static function error_key(): string {
    return 'facr_form_errors_' . get_current_user_id();
  }

  // --------------------------------------------------------------- form ----

  public static function render( string $action ): void {
    // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only screen state.
    $rule_id = isset( $_GET['rule'] ) ? sanitize_text_field( wp_unslash( $_GET['rule'] ) ) : '';
    $rule    = $action === 'edit' && $rule_id !== '' ? Store::get( $rule_id ) : null;

    $stashed = get_transient( self::error_key() );
    $errors  = [];
    if ( is_array( $stashed ) ) {
      delete_transient( self::error_key() );
      $errors = (array) ( $stashed['errors'] ?? [] );
      $rule   = (array) ( $stashed['input'] ?? $rule );
    }

    if ( ! $rule ) {
      // Prefill from the affiliate profile card's "Add rule for this affiliate" link.
      // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only prefill.
      $prefill_affiliate = isset( $_GET['affiliate_id'] ) ? (int) $_GET['affiliate_id'] : 0;
      $rule              = [
        'id'          => '',
        'status'      => 'active',
        'scope_type'  => $prefill_affiliate > 0 ? 'affiliate' : 'all',
        'scope_id'    => $prefill_affiliate,
        'target_type' => 'all',
        'target_ids'  => [],
        'rate'        => '',
        'rate_type'   => 'percentage',
        'starts_at'   => '',
        'ends_at'     => '',
        'note'        => '',
        'created_at'  => '',
      ];
    }

    echo '<div class="wrap">';
    printf(
      '<h1>%s</h1>',
      esc_html( $action === 'edit' ? __( 'Edit commission rule', 'fa-commission-rules' ) : __( 'Add commission rule', 'fa-commission-rules' ) )
    );

    echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
    wp_nonce_field( 'facr_save_rule' );
    echo '<input type="hidden" name="action" value="facr_save_rule">';
    printf( '<input type="hidden" name="facr_id" value="%s">', esc_attr( (string) $rule['id'] ) );
    printf( '<input type="hidden" name="facr_created_at" value="%s">', esc_attr( (string) $rule['created_at'] ) );

    echo '<table class="form-table" role="presentation"><tbody>';

    // 1. Audience.
    self::row(
      __( 'Applies to', 'fa-commission-rules' ),
      static function () use ( $rule ): void {
        $scopes = [ 'all' => __( 'Everyone', 'fa-commission-rules' ) ];
        if ( Fluent::has_pro() && Fluent::groups() ) {
          $scopes['group'] = __( 'An affiliate group', 'fa-commission-rules' );
        }
        $scopes['affiliate'] = __( 'One affiliate', 'fa-commission-rules' );

        echo '<select name="facr_scope_type" id="facr_scope_type">';
        foreach ( $scopes as $value => $label ) {
          printf( '<option value="%s"%s>%s</option>', esc_attr( $value ), selected( $rule['scope_type'], $value, false ), esc_html( $label ) );
        }
        echo '</select> ';

        echo '<select name="facr_scope_id" id="facr_scope_id">';
        printf( '<option value="0">%s</option>', esc_html__( '— choose —', 'fa-commission-rules' ) );
        foreach ( Fluent::groups() as $id => $name ) {
          printf(
            '<option data-scope="group" value="%d"%s>%s</option>',
            (int) $id,
            selected( $rule['scope_type'] === 'group' && (int) $rule['scope_id'] === (int) $id, true, false ),
            esc_html( $name )
          );
        }
        // ponytail: a plain 200-row select. Swap for a search field only if the
        // affiliate list actually outgrows it.
        foreach ( \FluentAffiliate\App\Models\Affiliate::orderBy( 'id', 'DESC' )->limit( 200 )->get() as $affiliate ) {
          printf(
            '<option data-scope="affiliate" value="%d"%s>%s</option>',
            (int) $affiliate->id,
            selected( $rule['scope_type'] === 'affiliate' && (int) $rule['scope_id'] === (int) $affiliate->id, true, false ),
            esc_html( Fluent::affiliate_label( (int) $affiliate->id ) )
          );
        }
        echo '</select>';
      },
      $errors['scope_id'] ?? '',
      'facr_scope_id'
    );

    // 2. Target.
    self::row(
      __( 'For the products', 'fa-commission-rules' ),
      static function () use ( $rule ): void {
        $targets = [ 'all' => __( 'All products', 'fa-commission-rules' ) ];
        if ( Fluent::has_woo() ) {
          $targets['category'] = __( 'A product category', 'fa-commission-rules' );
          $targets['product']  = __( 'A product or variation', 'fa-commission-rules' );
        }
        echo '<select name="facr_target_type" id="facr_target_type">';
        foreach ( $targets as $value => $label ) {
          printf( '<option value="%s"%s>%s</option>', esc_attr( $value ), selected( $rule['target_type'], $value, false ), esc_html( $label ) );
        }
        echo '</select>';

        if ( ! Fluent::has_woo() ) {
          return;
        }

        // Categories: a plain multi-select of the product_cat tree. Note the
        // name: each picker owns its own field, and the handler reads only the
        // one matching the chosen target type.
        echo '<p><select name="facr_target_ids_category[]" id="facr_target_cats" multiple size="6" style="min-width:320px;">';
        $terms = get_terms( [ 'taxonomy' => 'product_cat', 'hide_empty' => false ] );
        if ( ! is_wp_error( $terms ) ) {
          foreach ( $terms as $term ) {
            $depth = count( get_ancestors( (int) $term->term_id, 'product_cat', 'taxonomy' ) );
            printf(
              '<option value="%d"%s>%s%s</option>',
              (int) $term->term_id,
              selected( $rule['target_type'] === 'category' && in_array( (int) $term->term_id, (array) $rule['target_ids'], true ), true, false ),
              esc_html( str_repeat( '— ', $depth ) ),
              esc_html( $term->name )
            );
          }
        }
        echo '</select></p>';

        // Products and variations: WooCommerce's own search control, whose AJAX
        // endpoint requires the edit_products capability. A Fluent-only manager
        // without it would get a search box that silently returns nothing, so
        // they get a plain list instead.
        $selected_products = $rule['target_type'] === 'product' ? array_map( 'intval', (array) $rule['target_ids'] ) : [];

        if ( current_user_can( 'edit_products' ) ) {
          echo '<p><select class="wc-product-search" multiple name="facr_target_ids_product[]" id="facr_target_products" style="min-width:320px;"'
            . ' data-placeholder="' . esc_attr__( 'Search for a product or variation…', 'fa-commission-rules' ) . '"'
            . ' data-action="woocommerce_json_search_products_and_variations">';
          foreach ( $selected_products as $product_id ) {
            $product = function_exists( 'wc_get_product' ) ? wc_get_product( $product_id ) : null;
            printf(
              '<option value="%d" selected>%s</option>',
              $product_id,
              esc_html( $product ? $product->get_formatted_name() : '#' . $product_id )
            );
          }
          echo '</select></p>';
        } else {
          // ponytail: the newest 200 products plus whatever is already selected.
          // Add paging only if someone actually manages rules without edit_products
          // on a catalogue that big.
          $choices = $selected_products;
          if ( function_exists( 'wc_get_products' ) ) {
            foreach ( wc_get_products( [ 'limit' => 200, 'status' => 'publish', 'orderby' => 'title', 'order' => 'ASC', 'return' => 'ids' ] ) as $product_id ) {
              $choices[] = (int) $product_id;
            }
          }
          echo '<p><select name="facr_target_ids_product[]" id="facr_target_products" multiple size="6" style="min-width:320px;">';
          foreach ( array_values( array_unique( $choices ) ) as $product_id ) {
            $product = function_exists( 'wc_get_product' ) ? wc_get_product( $product_id ) : null;
            printf(
              '<option value="%d"%s>%s</option>',
              $product_id,
              selected( in_array( $product_id, $selected_products, true ), true, false ),
              esc_html( $product ? $product->get_formatted_name() : '#' . $product_id )
            );
          }
          echo '</select></p>';
          printf( '<p class="description">%s</p>', esc_html__( 'Product search needs the "edit products" capability, so this is a plain list instead.', 'fa-commission-rules' ) );
        }
      },
      $errors['target_ids'] ?? '',
      'facr_target_type'
    );

    // 3. Money.
    self::row(
      __( 'Commission', 'fa-commission-rules' ),
      static function () use ( $rule ): void {
        printf(
          '<input type="text" inputmode="decimal" name="facr_rate" id="facr_rate" value="%s" class="small-text" required> ',
          esc_attr( (string) $rule['rate'] )
        );
        echo '<select name="facr_rate_type" id="facr_rate_type">';
        printf( '<option value="percentage"%s>%s</option>', selected( $rule['rate_type'], 'percentage', false ), esc_html__( '% of the line total', 'fa-commission-rules' ) );
        printf( '<option value="flat"%s>%s</option>', selected( $rule['rate_type'], 'flat', false ), esc_html__( 'flat, per order line', 'fa-commission-rules' ) );
        echo '</select>';
        printf( '<p class="description">%s</p>', esc_html__( 'Applied to the same order total Fluent Affiliate commissions, so your tax and shipping settings are respected.', 'fa-commission-rules' ) );
      },
      $errors['rate'] ?? '',
      'facr_rate'
    );

    // 4. Time.
    self::row(
      __( 'Active period', 'fa-commission-rules' ),
      static function () use ( $rule ): void {
        printf(
          '<label for="facr_starts_at">%s</label> <input type="date" name="facr_starts_at" id="facr_starts_at" value="%s"> ',
          esc_html__( 'From', 'fa-commission-rules' ),
          esc_attr( (string) $rule['starts_at'] )
        );
        printf(
          '<label for="facr_ends_at">%s</label> <input type="date" name="facr_ends_at" id="facr_ends_at" value="%s"> ',
          esc_html__( 'Until', 'fa-commission-rules' ),
          esc_attr( (string) $rule['ends_at'] )
        );
        printf(
          '<button type="button" class="button" id="facr_preset_year">%s</button>',
          esc_html__( 'First 12 months', 'fa-commission-rules' )
        );
        printf( '<p class="description">%s</p>', esc_html__( 'Leave both empty for a rule that never expires.', 'fa-commission-rules' ) );
      },
      $errors['starts_at'] ?? ( $errors['ends_at'] ?? '' ),
      'facr_starts_at'
    );

    // 5. Note.
    self::row(
      __( 'Internal note', 'fa-commission-rules' ),
      static function () use ( $rule ): void {
        printf(
          '<input type="text" name="facr_note" id="facr_note" value="%s" class="regular-text">',
          esc_attr( (string) $rule['note'] )
        );
        printf( '<p class="description">%s</p>', esc_html__( 'Why this rule exists. Nobody remembers in a year.', 'fa-commission-rules' ) );
      },
      '',
      'facr_note'
    );

    // 6. Status.
    self::row(
      __( 'Status', 'fa-commission-rules' ),
      static function () use ( $rule ): void {
        echo '<select name="facr_status" id="facr_status">';
        printf( '<option value="active"%s>%s</option>', selected( $rule['status'], 'active', false ), esc_html__( 'Active', 'fa-commission-rules' ) );
        printf( '<option value="inactive"%s>%s</option>', selected( $rule['status'], 'inactive', false ), esc_html__( 'Inactive', 'fa-commission-rules' ) );
        echo '</select>';
      },
      '',
      'facr_status'
    );

    echo '</tbody></table>';

    // Rendered server-side so an edit screen states the rule in words even with
    // JavaScript off; the inline script below keeps it current while you type.
    printf(
      '<p class="description" style="font-size:14px;"><strong>%s</strong> <span id="facr_result">%s</span></p>',
      esc_html__( 'Result:', 'fa-commission-rules' ),
      esc_html( (string) $rule['id'] !== '' ? RulesPage::scope_label( $rule ) . ' — ' . RulesPage::describe( $rule ) : '' )
    );

    submit_button( __( 'Save rule', 'fa-commission-rules' ) );
    printf( ' <a class="button" href="%s">%s</a>', esc_url( Menu::page_url() ), esc_html__( 'Cancel', 'fa-commission-rules' ) );
    echo '</form>';
    ?>
    <script>
    ( function () {
      var l10n = <?php
        echo wp_json_encode(
          [
            'sentence' => __( '%1$s earns %2$s on %3$s.', 'fa-commission-rules' ),
            'from'     => __( ' From %s.', 'fa-commission-rules' ),
            'until'    => __( ' Until %s.', 'fa-commission-rules' ),
            'between'  => __( ' From %1$s to %2$s.', 'fa-commission-rules' ),
          ],
          JSON_HEX_TAG | JSON_HEX_AMP
        );
      ?>;

      var scopeType  = document.getElementById( 'facr_scope_type' );
      var scopeId    = document.getElementById( 'facr_scope_id' );
      var targetType = document.getElementById( 'facr_target_type' );
      var cats       = document.getElementById( 'facr_target_cats' );
      var products   = document.getElementById( 'facr_target_products' );
      var rate       = document.getElementById( 'facr_rate' );
      var rateType   = document.getElementById( 'facr_rate_type' );
      var startsAt   = document.getElementById( 'facr_starts_at' );
      var endsAt     = document.getElementById( 'facr_ends_at' );
      var preset     = document.getElementById( 'facr_preset_year' );
      var result     = document.getElementById( 'facr_result' );

      function optionText( select ) {
        return ( select && select.selectedIndex >= 0 ) ? select.options[ select.selectedIndex ].text : '';
      }

      function syncScope() {
        var mode = scopeType.value;
        scopeId.disabled = ( mode === 'all' );
        Array.prototype.forEach.call( scopeId.options, function ( option ) {
          if ( ! option.dataset.scope ) { return; }
          option.hidden = ( option.dataset.scope !== mode );
        } );
      }

      function syncTarget() {
        var mode = targetType.value;
        if ( cats ) { cats.closest( 'p' ).hidden = ( mode !== 'category' ); cats.disabled = ( mode !== 'category' ); }
        if ( products ) { products.closest( 'p' ).hidden = ( mode !== 'product' ); products.disabled = ( mode !== 'product' ); }
      }

      function syncResult() {
        if ( ! result ) { return; }
        var who   = scopeType.value === 'all' ? optionText( scopeType ) : optionText( scopeId );
        var money = ( rate.value || '0' ).trim() + ( rateType.value === 'percentage' ? '%' : '' );
        var what  = optionText( targetType );
        var when  = '';

        if ( startsAt.value && endsAt.value ) {
          when = l10n.between.replace( '%1$s', startsAt.value ).replace( '%2$s', endsAt.value );
        } else if ( startsAt.value ) {
          when = l10n.from.replace( '%s', startsAt.value );
        } else if ( endsAt.value ) {
          when = l10n.until.replace( '%s', endsAt.value );
        }

        result.textContent = l10n.sentence
          .replace( '%1$s', who )
          .replace( '%2$s', money )
          .replace( '%3$s', what ) + when;
      }

      if ( preset ) {
        preset.addEventListener( 'click', function () {
          var today = new Date();
          var end   = new Date( today.getTime() + 365 * 24 * 60 * 60 * 1000 );
          startsAt.value = today.toISOString().slice( 0, 10 );
          endsAt.value   = end.toISOString().slice( 0, 10 );
          syncResult();
        } );
      }

      [ scopeType, scopeId, targetType, rate, rateType, startsAt, endsAt ].forEach( function ( field ) {
        if ( ! field ) { return; }
        field.addEventListener( 'change', syncResult );
        field.addEventListener( 'input', syncResult );
      } );

      scopeType.addEventListener( 'change', syncScope );
      targetType.addEventListener( 'change', syncTarget );
      syncScope();
      syncTarget();
      syncResult();
    } )();
    </script>
    <?php
    echo '</div>';
  }

  /** One form-table row with an inline, aria-linked error. */
  private static function row( string $label, callable $field, string $error, string $for ): void {
    printf( '<tr><th scope="row"><label for="%s">%s</label></th><td>', esc_attr( $for ), esc_html( $label ) );
    $field();
    if ( $error !== '' ) {
      printf(
        '<p class="description" id="%s-error" style="color:#b32d2e;" role="alert">%s</p>',
        esc_attr( $for ),
        esc_html( $error )
      );
    }
    echo '</td></tr>';
  }
}
