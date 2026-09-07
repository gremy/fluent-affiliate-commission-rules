<?php
declare(strict_types=1);

namespace FACommissionRules\Admin;

defined( 'ABSPATH' ) || exit;

use FACommissionRules\Fluent;
use FACommissionRules\Labels;
use FACommissionRules\LineBuilder;
use FACommissionRules\Resolver;
use FACommissionRules\Store;

/** Read-only rule summaries on the affiliate profile and portal. */
final class Widgets {
  public function register(): void {
    add_filter( 'fluent_affiliate/affiliate_widgets', [ $this, 'affiliate_widget' ], 10, 2 );
    add_filter( 'fluent_affiliate/portal_notice_html', [ $this, 'portal_notice' ] );
  }

  /**
   * Every rule that could ever apply to this affiliate: their own, their
   * group's, the plugin's own "everyone" rules, and Fluent's own global rows.
   * Resolver::applicable()/effective() do the status/date/scope filtering and
   * the per-target collapsing; this just gathers the candidates.
   *
   * @return array<int,array<string,mixed>>
   */
  public static function rules_for_affiliate( int $affiliate_id, int $group_id ): array {
    $rules = Store::for_scope( 'affiliate', $affiliate_id );
    if ( $group_id > 0 ) {
      $rules = array_merge( $rules, Store::for_scope( 'group', $group_id ) );
    }
    $rules = array_merge( $rules, Store::for_scope( 'all', 0 ), Store::fluent_global_rules( 'sale' ) );
    usort(
      $rules,
      static fn( array $a, array $b ): int => ( $b['status'] === 'active' ? 1 : 0 ) <=> ( $a['status'] === 'active' ? 1 : 0 )
    );
    return $rules;
  }

  /** Individual / Group / Everyone — the source column, plain language throughout. */
  private static function source_label( array $rule ): string {
    switch ( (string) ( $rule['scope_type'] ?? 'all' ) ) {
      case 'affiliate':
        return __( 'Individual', 'fa-commission-rules' );
      case 'group':
        return __( 'Group', 'fa-commission-rules' );
      default:
        return __( 'Everyone', 'fa-commission-rules' );
    }
  }

  /**
   * @param array<int,array<string,string>> $widgets
   * @param object $affiliate
   * @return array<int,array<string,string>>
   */
  public function affiliate_widget( $widgets, $affiliate = null ) {
    if ( ! is_array( $widgets ) || ! is_object( $affiliate ) || ! isset( $affiliate->id ) ) {
      return $widgets;
    }

    $affiliate_id = (int) $affiliate->id;
    $group_id     = (int) ( $affiliate->group_id ?? 0 );
    $rules        = Resolver::effective(
      self::rules_for_affiliate( $affiliate_id, $group_id ),
      [ 'affiliate_id' => $affiliate_id, 'group_id' => $group_id ],
      current_time( 'Y-m-d' ),
      [ LineBuilder::class, 'target_line' ]
    );

    $rows = '';
    foreach ( $rules as $rule ) {
      $rows .= sprintf(
        '<tr><td>%s</td><td>%s</td><td>%s</td><td>%s</td></tr>',
        esc_html( Labels::target_label( $rule ) ),
        esc_html( Labels::rate_label( $rule ) ),
        esc_html( Labels::window_label( $rule ) ),
        esc_html( self::source_label( $rule ) )
      );
    }

    $guidance = '<p>' . esc_html__( 'Higher-priority rules take precedence; rates do not stack. The final rate depends on the products and referral type. Fluent global rows apply to initial sales only.', 'fa-commission-rules' ) . '</p>';
    $content = $rows !== ''
      ? '<table class="widefat striped"><thead><tr>'
        . '<th>' . esc_html__( 'For what', 'fa-commission-rules' ) . '</th>'
        . '<th>' . esc_html__( 'Rate', 'fa-commission-rules' ) . '</th>'
        . '<th>' . esc_html__( 'Window', 'fa-commission-rules' ) . '</th>'
        . '<th>' . esc_html__( 'Source', 'fa-commission-rules' ) . '</th>'
        . '</tr></thead><tbody>' . $rows . '</tbody></table>'
      : '<p>' . esc_html__( 'No commission rules apply to this affiliate. They earn the inherited rate.', 'fa-commission-rules' ) . '</p>';

    $widgets[] = [
      'title'   => esc_html__( 'Commission rules', 'fa-commission-rules' ),
      'action'  => sprintf(
        '<a href="%s" target="_blank" rel="noopener">%s</a>',
        esc_url( Menu::page_url( [ 'action' => 'add', 'affiliate_id' => (string) $affiliate_id ] ) ),
        esc_html__( 'Add rule for this affiliate', 'fa-commission-rules' )
      ),
      'content' => $guidance . $content,
    ];

    return $widgets;
  }

  /**
   * @param mixed $html
   * @return string
   */
  public function portal_notice( $html ) {
    $html      = is_string( $html ) ? $html : '';
    $affiliate = Fluent::affiliate_for_user( get_current_user_id() );
    if ( ! $affiliate ) {
      return $html;
    }

    $group_id = (int) ( $affiliate->group_id ?? 0 );
    $rules    = Resolver::effective(
      self::rules_for_affiliate( (int) $affiliate->id, $group_id ),
      [ 'affiliate_id' => (int) $affiliate->id, 'group_id' => $group_id ],
      current_time( 'Y-m-d' ),
      [ LineBuilder::class, 'target_line' ]
    );
    if ( ! $rules ) {
      return $html;
    }

    $items = '';
    foreach ( $rules as $rule ) {
      $items .= '<li>' . esc_html( self::plain_sentence( $rule ) )
        . ( ! empty( $rule['readonly'] ) ? ' ' . esc_html__( '(Fluent global rate; initial sales only.)', 'fa-commission-rules' ) : '' ) . '</li>';
    }

    return $html
      . '<div class="fa-commission-rules-card">'
      . '<h4>' . esc_html__( 'Your commission rules', 'fa-commission-rules' ) . '</h4>'
      . '<p>' . esc_html__( 'Higher-priority rules take precedence; rates do not stack. The final rate depends on the products and referral type. Fluent global rows apply to initial sales only.', 'fa-commission-rules' ) . '</p>'
      . '<ul>' . $items . '</ul>'
      . '</div>';
  }

  /** Plain language, no admin vocabulary, and the end date always spelled out. */
  private static function plain_sentence( array $rule ): string {
    $scope = $rule['target_type'] === 'all'
      ? Labels::qualify_target( __( 'on commissionable product-line totals', 'fa-commission-rules' ), $rule )
      : sprintf(
        /* translators: %s: the products or categories the rate covers */
        __( 'on %s', 'fa-commission-rules' ),
        Labels::target_label( $rule )
      );

    if ( (string) $rule['ends_at'] !== '' ) {
      return sprintf(
        /* translators: 1: commission rate, 2: what it applies to, 3: the last day it applies */
        __( 'When this rule takes precedence: %1$s %2$s, until %3$s.', 'fa-commission-rules' ),
        Labels::rate_label( $rule ),
        $scope,
        Labels::show_date( (string) $rule['ends_at'] )
      );
    }

    return sprintf(
      /* translators: 1: commission rate, 2: what it applies to */
      __( 'When this rule takes precedence: %1$s %2$s.', 'fa-commission-rules' ),
      Labels::rate_label( $rule ),
      $scope
    );
  }
}
