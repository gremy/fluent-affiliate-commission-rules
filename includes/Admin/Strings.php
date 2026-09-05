<?php
declare(strict_types=1);

namespace FACommissionRules\Admin;

defined( 'ABSPATH' ) || exit;

/**
 * Every string the admin app shows, translated once here and handed to the
 * browser as facrAdmin.i18n. All of OUR strings come from PHP (Element Plus's
 * own untranslated locale strings — date-picker month/weekday names, "No
 * data"/"Loading" — stay English, same as Fluent's own admin).
 *
 * @package FACommissionRules
 */
final class Strings {
  /** @return array<string,string> */
  public static function all(): array {
    return [
      // List screen.
      'page_title'           => __( 'Commission rules', 'fa-commission-rules' ),
      'add_rule'             => __( 'Add rule', 'fa-commission-rules' ),
      'add_first'            => __( 'Add the first rule', 'fa-commission-rules' ),
      /* translators: %s: the commission rate every affiliate currently inherits */
      'empty_body'           => __( 'No commission rules yet. Every affiliate earns the inherited default rate of %s.', 'fa-commission-rules' ),
      'no_match'             => __( 'No rule matches these filters.', 'fa-commission-rules' ),
      'filter_all_audiences' => __( 'All audiences', 'fa-commission-rules' ),
      'filter_affiliate'     => __( 'Affiliate', 'fa-commission-rules' ),
      'filter_group'         => __( 'Group', 'fa-commission-rules' ),
      'filter_everyone'      => __( 'Everyone', 'fa-commission-rules' ),
      'filter_any_target'    => __( 'Any target', 'fa-commission-rules' ),
      'filter_product'       => __( 'A product', 'fa-commission-rules' ),
      'filter_category'      => __( 'A category', 'fa-commission-rules' ),
      'filter_all_products'  => __( 'All products', 'fa-commission-rules' ),
      'filter_any_status'    => __( 'Any status', 'fa-commission-rules' ),
      'filter_active'        => __( 'Active', 'fa-commission-rules' ),
      'filter_inactive'      => __( 'Inactive', 'fa-commission-rules' ),
      'search_placeholder'   => __( 'Search notes, products, people…', 'fa-commission-rules' ),
      'col_who'              => __( 'Who', 'fa-commission-rules' ),
      'col_what'             => __( 'For what', 'fa-commission-rules' ),
      'col_rate'             => __( 'Rate', 'fa-commission-rules' ),
      'col_window'           => __( 'Window', 'fa-commission-rules' ),
      'col_status'           => __( 'Status', 'fa-commission-rules' ),
      'col_note'             => __( 'Note', 'fa-commission-rules' ),
      'col_actions'          => __( 'Actions', 'fa-commission-rules' ),
      'status_global'        => __( 'Global (Fluent)', 'fa-commission-rules' ),
      'status_inactive'      => __( 'Inactive', 'fa-commission-rules' ),
      'status_effective'     => __( 'Effective', 'fa-commission-rules' ),
      /* translators: %s: the narrower rule that takes precedence for some products */
      'badge_shadow'         => __( 'Overridden for some products by: %s', 'fa-commission-rules' ),
      /* translators: 1: who the narrower rule applies to, 2: the products it targets */
      'badge_shadow_by'      => __( '%1$s → %2$s', 'fa-commission-rules' ),
      'badge_tie'            => __( 'Conflict: two rules are equally specific', 'fa-commission-rules' ),
      'readonly_hint'        => __( "Fluent's global rates are read-only here; edit them in Fluent Affiliate's WooCommerce settings.", 'fa-commission-rules' ),
      'readonly_short'       => __( 'Read-only', 'fa-commission-rules' ),
      'edit'                 => __( 'Edit', 'fa-commission-rules' ),
      'delete'               => __( 'Delete', 'fa-commission-rules' ),
      'activate'             => __( 'Activate', 'fa-commission-rules' ),
      'deactivate'           => __( 'Deactivate', 'fa-commission-rules' ),
      /* translators: %d: number of selected rules */
      'selected_count'       => __( '%d selected', 'fa-commission-rules' ),
      'confirm_title'        => __( 'Please confirm', 'fa-commission-rules' ),
      'confirm_ok'           => __( 'Yes, continue', 'fa-commission-rules' ),
      'confirm_cancel'       => __( 'Cancel', 'fa-commission-rules' ),
      /* translators: %s: the store's inherited default commission rate */
      'confirm_delete_one'   => __( 'Delete this rule? For those products the affiliate reverts to the next matching rule, or to the default rate of %s.', 'fa-commission-rules' ),
      /* translators: %s: the store's inherited default commission rate */
      'confirm_delete_many'  => __( 'Delete the selected rules? For those products each affiliate reverts to the next matching rule, or to the inherited default rate of %s.', 'fa-commission-rules' ),
      /* translators: %s: the store's inherited default commission rate */
      'confirm_deactivate'   => __( 'Deactivate the selected rules? For those products each affiliate reverts to the next matching rule, or to the inherited default rate of %s.', 'fa-commission-rules' ),
      'deleted'              => __( 'Rule deleted.', 'fa-commission-rules' ),
      /* translators: %s: what the saved rule does, in plain words */
      'rule_saved'           => __( 'Rule saved: %s', 'fa-commission-rules' ),
      'tie_title'            => __( 'Rule saved, with a conflict', 'fa-commission-rules' ),
      'tie_warning'          => __( 'Another active rule is exactly as specific, so the newer of the two wins. Look for the Conflict flag in the list.', 'fa-commission-rules' ),
      'error_generic'        => __( 'Something went wrong. Please try again.', 'fa-commission-rules' ),

      // Editor drawer.
      'editor_add_title'     => __( 'Add commission rule', 'fa-commission-rules' ),
      'editor_edit_title'    => __( 'Edit commission rule', 'fa-commission-rules' ),
      'section_audience'     => __( 'Applies to', 'fa-commission-rules' ),
      'section_target'       => __( 'For the products', 'fa-commission-rules' ),
      'section_money'        => __( 'Commission', 'fa-commission-rules' ),
      'section_time'         => __( 'Active period', 'fa-commission-rules' ),
      'section_note'         => __( 'Internal note', 'fa-commission-rules' ),
      'scope_all'            => __( 'Everyone', 'fa-commission-rules' ),
      'scope_group'          => __( 'An affiliate group', 'fa-commission-rules' ),
      'scope_affiliate'      => __( 'One affiliate', 'fa-commission-rules' ),
      'choose_group'         => __( 'Choose a group', 'fa-commission-rules' ),
      'choose_affiliate'     => __( 'Choose an affiliate', 'fa-commission-rules' ),
      'target_all'           => __( 'All products', 'fa-commission-rules' ),
      'target_category'      => __( 'A product category', 'fa-commission-rules' ),
      'target_product'       => __( 'A product or variation', 'fa-commission-rules' ),
      'choose_categories'    => __( 'Choose categories', 'fa-commission-rules' ),
      'search_products'      => __( 'Search for a product or variation…', 'fa-commission-rules' ),
      'search_min'           => __( 'Type at least two characters', 'fa-commission-rules' ),
      'search_none'          => __( 'No products found', 'fa-commission-rules' ),
      'rate_percentage'      => __( '% of the line total', 'fa-commission-rules' ),
      'rate_flat'            => __( 'flat, per order line', 'fa-commission-rules' ),
      'rate_help'            => __( 'Applied per matching order line, to the same line totals Fluent Affiliate commissions — so your tax and shipping settings are respected.', 'fa-commission-rules' ),
      'starts_at'            => __( 'From', 'fa-commission-rules' ),
      'ends_at'              => __( 'Until', 'fa-commission-rules' ),
      'preset_year'          => __( 'First 12 months', 'fa-commission-rules' ),
      'time_help'            => __( 'Leave both empty for a rule that never expires.', 'fa-commission-rules' ),
      'note_help'            => __( 'Why this rule exists. Nobody remembers in a year.', 'fa-commission-rules' ),
      'status_label'         => __( 'Status', 'fa-commission-rules' ),
      'status_active'        => __( 'Active', 'fa-commission-rules' ),
      'status_inactive_option' => __( 'Inactive', 'fa-commission-rules' ),
      'result_label'         => __( 'Result:', 'fa-commission-rules' ),
      'save'                 => __( 'Save rule', 'fa-commission-rules' ),
      'cancel'               => __( 'Cancel', 'fa-commission-rules' ),

      // Live "Result:" sentence (mirrors Labels::describe()).
      /* translators: %1$s: who the rule applies to (e.g. "This affiliate"), %2$s: the rate (e.g. "10%"), %3$s: what it covers (e.g. "Coffee") */
      'sentence'             => __( '%1$s earns %2$s on %3$s.', 'fa-commission-rules' ),
      /* translators: %s: the start date the rule takes effect */
      'sentence_from'        => __( ' From %s.', 'fa-commission-rules' ),
      /* translators: %s: the date the rule stops applying */
      'sentence_until'       => __( ' Until %s.', 'fa-commission-rules' ),
      /* translators: %1$s: start date, %2$s: end date */
      'sentence_between'     => __( ' From %1$s to %2$s.', 'fa-commission-rules' ),
      'sentence_everyone'    => __( 'Everyone', 'fa-commission-rules' ),
      'sentence_group'       => __( 'This group', 'fa-commission-rules' ),
      'sentence_affiliate'   => __( 'This affiliate', 'fa-commission-rules' ),
      'sentence_all_products' => __( 'all products', 'fa-commission-rules' ),
      'sentence_category'    => __( 'the chosen categories', 'fa-commission-rules' ),
      'sentence_product'     => __( 'the chosen products', 'fa-commission-rules' ),
      /* translators: %s: a flat commission amount, per order line, already in store currency */
      'flat_suffix'          => __( '%s flat', 'fa-commission-rules' ),
    ];
  }
}
