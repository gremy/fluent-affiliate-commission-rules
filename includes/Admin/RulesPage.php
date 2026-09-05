<?php
declare(strict_types=1);

namespace FACommissionRules\Admin;

defined( 'ABSPATH' ) || exit;

use FACommissionRules\Fluent;
use FACommissionRules\Resolver;
use FACommissionRules\Store;

/**
 * The rules list: filter bar, table sorted most-specific first, bulk actions,
 * and an empty state that names the rate everyone inherits today.
 *
 * @package FACommissionRules
 */
final class RulesPage {
  public static function render(): void {
    if ( ! Fluent::can_manage() ) {
      wp_die( esc_html__( 'You do not have permission to manage commission rules.', 'fa-commission-rules' ), '', [ 'response' => 403 ] );
    }

    // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only screen state, no side effects.
    $action = isset( $_GET['action'] ) ? sanitize_key( wp_unslash( $_GET['action'] ) ) : '';
    if ( in_array( $action, [ 'add', 'edit' ], true ) ) {
      RuleForm::render( $action );
      return;
    }

    // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only filter state.
    $filter_scope = isset( $_GET['facr_scope'] ) ? sanitize_key( wp_unslash( $_GET['facr_scope'] ) ) : '';
    // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only filter state.
    $filter_target = isset( $_GET['facr_target'] ) ? sanitize_key( wp_unslash( $_GET['facr_target'] ) ) : '';
    // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only filter state.
    $filter_status = isset( $_GET['facr_status'] ) ? sanitize_key( wp_unslash( $_GET['facr_status'] ) ) : '';
    // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only filter state.
    $search = isset( $_GET['facr_s'] ) ? sanitize_text_field( wp_unslash( $_GET['facr_s'] ) ) : '';

    // Both maps are computed on the WHOLE list: a rule filtered out of the view
    // still overrides — and still conflicts with — the rules that are in it.
    $all_rules = array_merge( Store::all(), Store::fluent_global_rules() );
    $shadow    = Resolver::shadow_map( $all_rules );
    $ties      = Resolver::tie_map( $all_rules );

    $rules = array_values(
      array_filter(
        $all_rules,
        static function ( array $rule ) use ( $filter_scope, $filter_target, $filter_status, $search ): bool {
          if ( $filter_scope !== '' && $rule['scope_type'] !== $filter_scope ) {
            return false;
          }
          if ( $filter_target !== '' && $rule['target_type'] !== $filter_target ) {
            return false;
          }
          if ( $filter_status !== '' && $rule['status'] !== $filter_status ) {
            return false;
          }
          if ( $search === '' ) {
            return true;
          }
          $haystack = implode( ' ', [ (string) $rule['note'], self::scope_label( $rule ), self::target_label( $rule ) ] );
          return stripos( $haystack, $search ) !== false;
        }
      )
    );

    // Most specific first, so the rules that actually fire are on top.
    usort(
      $rules,
      static function ( array $a, array $b ): int {
        $rank = static fn( array $r ): int =>
          ( [ 'affiliate' => 3, 'group' => 2, 'all' => 1 ][ $r['scope_type'] ] ?? 1 ) * 10
          + ( [ 'product' => 3, 'category' => 2, 'all' => 1 ][ $r['target_type'] ] ?? 1 );
        return $rank( $b ) <=> $rank( $a ) ?: strcmp( (string) $b['created_at'], (string) $a['created_at'] );
      }
    );

    echo '<div class="wrap">';
    printf( '<h1 class="wp-heading-inline">%s</h1>', esc_html__( 'Commission rules', 'fa-commission-rules' ) );
    printf(
      ' <a href="%s" class="page-title-action">%s</a>',
      esc_url( Menu::page_url( [ 'action' => 'add' ] ) ),
      esc_html__( 'Add rule', 'fa-commission-rules' )
    );
    echo '<hr class="wp-header-end">';

    self::notice();

    // ---- filter bar -------------------------------------------------------
    echo '<form method="get" style="margin:12px 0;">';
    printf( '<input type="hidden" name="page" value="%s">', esc_attr( FACR_PAGE ) );
    echo '<select name="facr_scope">';
    printf( '<option value="">%s</option>', esc_html__( 'All audiences', 'fa-commission-rules' ) );
    foreach ( [
      'affiliate' => __( 'Affiliate', 'fa-commission-rules' ),
      'group'     => __( 'Group', 'fa-commission-rules' ),
      'all'       => __( 'Everyone', 'fa-commission-rules' ),
    ] as $value => $label ) {
      printf( '<option value="%s"%s>%s</option>', esc_attr( $value ), selected( $filter_scope, $value, false ), esc_html( $label ) );
    }
    echo '</select> ';
    echo '<select name="facr_target">';
    printf( '<option value="">%s</option>', esc_html__( 'Any target', 'fa-commission-rules' ) );
    foreach ( [
      'product'  => __( 'A product', 'fa-commission-rules' ),
      'category' => __( 'A category', 'fa-commission-rules' ),
      'all'      => __( 'All products', 'fa-commission-rules' ),
    ] as $value => $label ) {
      printf( '<option value="%s"%s>%s</option>', esc_attr( $value ), selected( $filter_target, $value, false ), esc_html( $label ) );
    }
    echo '</select> ';
    echo '<select name="facr_status">';
    printf( '<option value="">%s</option>', esc_html__( 'Any status', 'fa-commission-rules' ) );
    foreach ( [
      'active'   => __( 'Active', 'fa-commission-rules' ),
      'inactive' => __( 'Inactive', 'fa-commission-rules' ),
    ] as $value => $label ) {
      printf( '<option value="%s"%s>%s</option>', esc_attr( $value ), selected( $filter_status, $value, false ), esc_html( $label ) );
    }
    echo '</select> ';
    printf(
      '<input type="search" name="facr_s" value="%s" placeholder="%s" aria-label="%s"> ',
      esc_attr( $search ),
      esc_attr__( 'Search notes, products, people…', 'fa-commission-rules' ),
      esc_attr__( 'Search commission rules', 'fa-commission-rules' )
    );
    submit_button( __( 'Filter', 'fa-commission-rules' ), '', '', false );
    echo '</form>';

    // ---- empty state ------------------------------------------------------
    if ( ! $rules && $all_rules ) {
      printf(
        '<div class="notice notice-info inline"><p>%s</p></div>',
        esc_html__( 'No rule matches these filters.', 'fa-commission-rules' )
      );
      echo '</div>';
      return;
    }

    if ( ! $rules ) {
      printf(
        '<div class="notice notice-info inline"><p><strong>%s</strong><br>%s</p><p><a class="button button-primary" href="%s">%s</a></p></div>',
        esc_html__( 'No commission rules yet.', 'fa-commission-rules' ),
        esc_html(
          sprintf(
            /* translators: %s: the commission rate every affiliate currently inherits */
            __( 'Every affiliate earns the inherited default rate of %s.', 'fa-commission-rules' ),
            self::default_rate_label()
          )
        ),
        esc_url( Menu::page_url( [ 'action' => 'add' ] ) ),
        esc_html__( 'Add the first rule', 'fa-commission-rules' )
      );
      echo '</div>';
      return;
    }

    // ---- table ------------------------------------------------------------
    echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" onsubmit="return facrConfirmBulk(this);">';
    wp_nonce_field( 'facr_bulk' );
    echo '<input type="hidden" name="action" value="facr_bulk">';
    echo '<div class="tablenav top"><div class="alignleft actions bulkactions">';
    echo '<select name="facr_bulk_action">';
    printf( '<option value="">%s</option>', esc_html__( 'Bulk actions', 'fa-commission-rules' ) );
    printf( '<option value="activate">%s</option>', esc_html__( 'Activate', 'fa-commission-rules' ) );
    printf( '<option value="deactivate">%s</option>', esc_html__( 'Deactivate', 'fa-commission-rules' ) );
    printf( '<option value="delete">%s</option>', esc_html__( 'Delete', 'fa-commission-rules' ) );
    echo '</select> ';
    submit_button( __( 'Apply', 'fa-commission-rules' ), '', '', false );
    echo '</div></div>';

    echo '<div style="overflow-x:auto;"><table class="wp-list-table widefat fixed striped"><thead><tr>';
    echo '<td class="manage-column check-column"></td>';
    printf( '<th>%s</th>', esc_html__( 'Who', 'fa-commission-rules' ) );
    printf( '<th>%s</th>', esc_html__( 'For what', 'fa-commission-rules' ) );
    printf( '<th>%s</th>', esc_html__( 'Rate', 'fa-commission-rules' ) );
    printf( '<th>%s</th>', esc_html__( 'Window', 'fa-commission-rules' ) );
    printf( '<th>%s</th>', esc_html__( 'Status', 'fa-commission-rules' ) );
    printf( '<th>%s</th>', esc_html__( 'Note', 'fa-commission-rules' ) );
    echo '</tr></thead><tbody>';

    foreach ( $rules as $rule ) {
      $readonly = ! empty( $rule['readonly'] );
      echo '<tr>';
      echo '<th scope="row" class="check-column">';
      if ( ! $readonly ) {
        printf( '<input type="checkbox" name="facr_ids[]" value="%s">', esc_attr( (string) $rule['id'] ) );
      }
      echo '</th>';

      echo '<td>' . esc_html( self::scope_label( $rule ) );
      if ( ! $readonly ) {
        printf(
          '<div class="row-actions"><span class="edit"><a href="%s">%s</a></span> | <span class="delete"><a href="%s" onclick="return confirm(%s);">%s</a></span></div>',
          esc_url( Menu::page_url( [ 'action' => 'edit', 'rule' => (string) $rule['id'] ] ) ),
          esc_html__( 'Edit', 'fa-commission-rules' ),
          esc_url(
            wp_nonce_url(
              add_query_arg(
                [ 'action' => 'facr_delete_rule', 'rule' => (string) $rule['id'] ],
                admin_url( 'admin-post.php' )
              ),
              'facr_delete_rule_' . $rule['id']
            )
          ),
          esc_attr(
            wp_json_encode(
              sprintf(
                /* translators: %s: the store's inherited default commission rate */
                __( 'Delete this rule? For those products the affiliate reverts to the next matching rule, or to the default rate of %s.', 'fa-commission-rules' ),
                self::default_rate_label()
              ),
              JSON_HEX_TAG | JSON_HEX_AMP
            )
          ),
          esc_html__( 'Delete', 'fa-commission-rules' )
        );
      }
      echo '</td>';

      printf( '<td>%s</td>', esc_html( self::target_label( $rule ) ) );
      printf( '<td>%s</td>', esc_html( self::rate_label( $rule ) ) );
      printf( '<td>%s</td>', esc_html( self::window_label( $rule ) ) );

      echo '<td>';
      if ( $readonly ) {
        printf( '<span class="dashicons dashicons-lock" aria-hidden="true"></span> %s', esc_html__( 'Global (Fluent)', 'fa-commission-rules' ) );
      } elseif ( $rule['status'] !== 'active' ) {
        echo esc_html__( 'Inactive', 'fa-commission-rules' );
      } elseif ( ! empty( $shadow[ $rule['id'] ] ) ) {
        // Looked up in the unfiltered list: the overriding rule is very often
        // exactly the one the current filter hides. Partial, not dead: the
        // shadowing rule is narrower, so this rule still wins other products.
        $by = self::rule_by_id( $all_rules, (string) $shadow[ $rule['id'] ] );
        printf(
          '<span class="dashicons dashicons-info-outline" aria-hidden="true"></span> %s',
          esc_html(
            sprintf(
              /* translators: %s: the narrower rule that takes precedence for some products */
              __( 'Overridden for some products by: %s', 'fa-commission-rules' ),
              $by ? self::scope_label( $by ) . ' → ' . self::target_label( $by ) : ''
            )
          )
        );
      } else {
        printf( '<span class="dashicons dashicons-yes" aria-hidden="true"></span> %s', esc_html__( 'Effective', 'fa-commission-rules' ) );
      }
      if ( ! $readonly && ! empty( $ties[ $rule['id'] ] ) ) {
        // Icon AND words: colour alone never carries meaning here.
        printf(
          '<br><span class="dashicons dashicons-warning" aria-hidden="true"></span> %s',
          esc_html__( 'Conflict: two rules are equally specific', 'fa-commission-rules' )
        );
      }
      echo '</td>';

      printf( '<td>%s</td>', esc_html( (string) $rule['note'] ) );
      echo '</tr>';
    }

    echo '</tbody></table></div></form>';
    ?>
    <script>
    function facrConfirmBulk( form ) {
      if ( form.facr_bulk_action.value !== 'delete' ) { return true; }
      return window.confirm( <?php echo wp_json_encode( __( 'Delete the selected rules? For those products each affiliate reverts to the next matching rule, or to the inherited default rate.', 'fa-commission-rules' ), JSON_HEX_TAG | JSON_HEX_AMP ); ?> );
    }
    </script>
    <?php
    echo '</div>';
  }

  /** @param array<int,array<string,mixed>> $rules */
  private static function rule_by_id( array $rules, string $id ): ?array {
    foreach ( $rules as $rule ) {
      if ( (string) $rule['id'] === $id ) {
        return $rule;
      }
    }
    return null;
  }

  /** @param array<string,mixed> $rule */
  public static function scope_label( array $rule ): string {
    switch ( $rule['scope_type'] ) {
      case 'affiliate':
        return sprintf(
          /* translators: %s: affiliate name and id */
          __( 'Affiliate: %s', 'fa-commission-rules' ),
          Fluent::affiliate_label( (int) $rule['scope_id'] )
        );
      case 'group':
        return sprintf(
          /* translators: %s: affiliate group name */
          __( 'Group: %s', 'fa-commission-rules' ),
          Fluent::group_name( (int) $rule['scope_id'] )
        );
      default:
        return __( 'Everyone', 'fa-commission-rules' );
    }
  }

  /** @param array<string,mixed> $rule */
  public static function target_label( array $rule ): string {
    if ( $rule['target_type'] === 'all' ) {
      return __( 'All products', 'fa-commission-rules' );
    }
    $names = [];
    foreach ( (array) $rule['target_ids'] as $id ) {
      if ( $rule['target_type'] === 'category' ) {
        $term    = get_term( (int) $id, 'product_cat' );
        $names[] = ( $term && ! is_wp_error( $term ) ) ? $term->name : '#' . (int) $id;
      } else {
        $title   = get_the_title( (int) $id );
        $names[] = $title !== '' ? $title : '#' . (int) $id;
      }
    }
    $list = implode( ', ', $names );

    return $rule['target_type'] === 'category'
      ? sprintf(
        /* translators: %s: comma-separated product category names */
        __( 'Category: %s', 'fa-commission-rules' ),
        $list
      )
      : sprintf(
        /* translators: %s: comma-separated product or variation names */
        __( 'Product: %s', 'fa-commission-rules' ),
        $list
      );
  }

  /** @param array<string,mixed> $rule */
  public static function rate_label( array $rule ): string {
    if ( $rule['rate_type'] === 'percentage' ) {
      return rtrim( rtrim( number_format( (float) $rule['rate'], 2, '.', '' ), '0' ), '.' ) . '%';
    }
    return sprintf(
      /* translators: %s: a flat commission amount, per order line, already in store currency */
      __( '%s flat', 'fa-commission-rules' ),
      Fluent::money( (float) $rule['rate'] )
    );
  }

  /** A stored Y-m-d date in the site's own date format. */
  public static function show_date( string $date ): string {
    $stamp = $date !== '' ? strtotime( $date ) : false;
    return $stamp ? date_i18n( (string) get_option( 'date_format' ), $stamp ) : $date;
  }

  /** @param array<string,mixed> $rule */
  public static function window_label( array $rule ): string {
    $starts = self::show_date( (string) $rule['starts_at'] );
    $ends   = self::show_date( (string) $rule['ends_at'] );
    if ( $starts === '' && $ends === '' ) {
      return __( 'Always', 'fa-commission-rules' );
    }
    if ( $starts !== '' && $ends !== '' ) {
      return sprintf(
        /* translators: 1: start date, 2: end date */
        __( '%1$s to %2$s', 'fa-commission-rules' ),
        $starts,
        $ends
      );
    }
    return $starts !== ''
      ? sprintf(
        /* translators: %s: start date */
        __( 'From %s', 'fa-commission-rules' ),
        $starts
      )
      : sprintf(
        /* translators: %s: end date */
        __( 'Until %s', 'fa-commission-rules' ),
        $ends
      );
  }

  /** One plain-language line, reused by the affiliate profile card and the portal. */
  public static function describe( array $rule ): string {
    return sprintf(
      /* translators: 1: rate, 2: what the rate applies to, 3: the date window */
      __( '%1$s on %2$s (%3$s)', 'fa-commission-rules' ),
      self::rate_label( $rule ),
      self::target_label( $rule ),
      self::window_label( $rule )
    );
  }

  /** The store-wide rate an affiliate falls back to when nothing else matches. */
  private static function default_rate_label(): string {
    $rate      = (float) Fluent::referral_setting( 'rate', 0 );
    $rate_type = Fluent::referral_setting( 'rate_type', 'percentage' );
    return $rate_type === 'percentage'
      ? rtrim( rtrim( number_format( $rate, 2, '.', '' ), '0' ), '.' ) . '%'
      : Fluent::money( $rate );
  }

  private static function notice(): void {
    // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only redirect flag.
    $notice = isset( $_GET['facr_notice'] ) ? sanitize_key( wp_unslash( $_GET['facr_notice'] ) ) : '';
    if ( $notice === '' ) {
      return;
    }
    $messages = [
      'saved'          => [ 'success', __( 'Rule saved.', 'fa-commission-rules' ) ],
      'saved_conflict' => [ 'warning', __( 'Rule saved, but another active rule is exactly as specific, so the newer of the two wins. Look for the Conflict flag below.', 'fa-commission-rules' ) ],
      'deleted'        => [ 'success', __( 'Rule deleted.', 'fa-commission-rules' ) ],
      'bulk'           => [ 'success', __( 'Bulk action applied.', 'fa-commission-rules' ) ],
      'missing'        => [ 'error', __( 'That rule no longer exists.', 'fa-commission-rules' ) ],
      'readonly'       => [ 'error', __( "Fluent's global rates are read-only here; edit them in Fluent Affiliate's WooCommerce settings.", 'fa-commission-rules' ) ],
    ];
    if ( ! isset( $messages[ $notice ] ) ) {
      return;
    }
    [ $level, $message ] = $messages[ $notice ];
    // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only redirect detail.
    $detail = isset( $_GET['facr_detail'] ) ? sanitize_text_field( wp_unslash( $_GET['facr_detail'] ) ) : '';
    printf(
      '<div class="notice notice-%s is-dismissible"><p>%s%s</p></div>',
      esc_attr( $level ),
      esc_html( $message ),
      $detail !== '' ? ' ' . esc_html( $detail ) : ''
    );
  }
}
