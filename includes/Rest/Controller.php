<?php
declare(strict_types=1);

namespace FACommissionRules\Rest;

defined( 'ABSPATH' ) || exit;

use FACommissionRules\Fluent;
use FACommissionRules\Labels;
use FACommissionRules\Resolver;
use FACommissionRules\Store;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

/**
 * The admin app's API. Every route is gated on Fluent's own manage_all_data
 * permission and authenticated by the standard wp_rest nonce, exactly like
 * Fluent Affiliate's own SPA.
 *
 * @package FACommissionRules
 */
final class Controller {
  public const NAMESPACE = 'fa-commission-rules/v1';

  public function register(): void {
    add_action( 'rest_api_init', [ $this, 'routes' ] );
  }

  public function routes(): void {
    $permission = [ Fluent::class, 'can_manage' ];

    register_rest_route(
      self::NAMESPACE,
      '/rules',
      [
        [ 'methods' => 'GET', 'callback' => [ $this, 'index' ], 'permission_callback' => $permission ],
        [ 'methods' => 'POST', 'callback' => [ $this, 'save' ], 'permission_callback' => $permission ],
      ]
    );
    // Registered before the /rules/{id} pattern so a bulk POST is never read as an id.
    register_rest_route(
      self::NAMESPACE,
      '/rules/bulk',
      [ 'methods' => 'POST', 'callback' => [ $this, 'bulk' ], 'permission_callback' => $permission ]
    );
    register_rest_route(
      self::NAMESPACE,
      '/rules/(?P<id>[A-Za-z0-9:_-]+)',
      [ 'methods' => 'DELETE', 'callback' => [ $this, 'delete' ], 'permission_callback' => $permission ]
    );
    register_rest_route(
      self::NAMESPACE,
      '/options',
      [ 'methods' => 'GET', 'callback' => [ $this, 'options' ], 'permission_callback' => $permission ]
    );
    register_rest_route(
      self::NAMESPACE,
      '/products',
      [
        'methods'             => 'GET',
        'callback'            => [ $this, 'products' ],
        'permission_callback' => $permission,
        'args'                => [
          'search' => [ 'type' => 'string', 'default' => '', 'sanitize_callback' => 'sanitize_text_field' ],
        ],
      ]
    );
  }

  /**
   * One request value, safe to hand to sanitize_key()/sanitize_text_field()/(int).
   * A JSON body can put an array where a scalar is expected, and every one of
   * those sanitisers throws a TypeError on an array.
   *
   * @param mixed $value
   */
  public static function scalar( $value ): string {
    return is_scalar( $value ) ? (string) $value : '';
  }

  // --------------------------------------------------------------- reads ---

  public function index(): WP_REST_Response {
    // Both maps are computed on the WHOLE list: a rule the client filters out
    // of view still overrides — and still conflicts with — the rules in it.
    $rules = array_merge( Store::all(), Store::fluent_global_rules() );
    return new WP_REST_Response(
      [
        'rules'        => array_map( [ $this, 'present' ], $rules ),
        'shadow'       => (object) Resolver::shadow_map( $rules ),
        'tie'          => (object) Resolver::tie_map( $rules ),
        'default_rate' => Labels::default_rate_label(),
      ]
    );
  }

  public function options(): WP_REST_Response {
    $groups = [];
    foreach ( Fluent::groups() as $id => $name ) {
      $groups[] = [ 'id' => (int) $id, 'label' => (string) $name ];
    }
    return new WP_REST_Response(
      [
        'affiliates'   => Fluent::affiliates(),
        'groups'       => $groups,
        'categories'   => $this->categories(),
        'has_pro'      => Fluent::has_pro(),
        'has_woo'      => Fluent::has_woo(),
        'default_rate' => Labels::default_rate_label(),
      ]
    );
  }

  /** @return WP_REST_Response|WP_Error */
  public function products( WP_REST_Request $request ) {
    $term = trim( self::scalar( $request->get_param( 'search' ) ) );
    if ( mb_strlen( $term ) < 2 ) {
      return new WP_Error( 'facr_search_short', __( 'Type at least two characters to search.', 'fa-commission-rules' ), [ 'status' => 400 ] );
    }
    if ( ! Fluent::has_woo() || ! class_exists( 'WC_Data_Store' ) || ! function_exists( 'wc_get_product' ) ) {
      return new WP_REST_Response( [] );
    }
    // wc_get_products() has no search argument; this is the data store call
    // behind WooCommerce's own admin product search, variations included.
    $ids = \WC_Data_Store::load( 'product' )->search_products( $term, '', true, false, 20 );
    $out = [];
    foreach ( array_unique( array_map( 'intval', (array) $ids ) ) as $id ) {
      if ( $id <= 0 ) {
        continue; // search_products() pads its result with a trailing 0.
      }
      $product = wc_get_product( $id );
      if ( $product ) {
        $out[] = [ 'id' => $id, 'label' => Labels::product_label( $product ) ];
      }
    }
    return new WP_REST_Response( $out );
  }

  /**
   * @param array<string,mixed> $rule
   * @return array<string,mixed>
   */
  private function present( array $rule ): array {
    $rule['labels']         = Labels::for_rule( $rule );
    $rule['target_options'] = Labels::target_options( $rule );
    return $rule;
  }

  /** @return array<int,array{id:int,label:string,name:string}> */
  private function categories(): array {
    if ( ! Fluent::has_woo() || ! taxonomy_exists( 'product_cat' ) ) {
      return [];
    }
    $terms = get_terms( [ 'taxonomy' => 'product_cat', 'hide_empty' => false ] );
    if ( is_wp_error( $terms ) ) {
      return [];
    }
    $out = [];
    foreach ( $terms as $term ) {
      $path = [];
      foreach ( array_reverse( get_ancestors( (int) $term->term_id, 'product_cat', 'taxonomy' ) ) as $ancestor_id ) {
        $ancestor = get_term( (int) $ancestor_id, 'product_cat' );
        if ( $ancestor && ! is_wp_error( $ancestor ) ) {
          $path[] = (string) $ancestor->name;
        }
      }
      $path[] = (string) $term->name;
      $out[]  = [ 'id' => (int) $term->term_id, 'label' => implode( ' / ', $path ), 'name' => (string) $term->name ];
    }
    usort( $out, static fn( array $a, array $b ): int => strcasecmp( $a['label'], $b['label'] ) );
    return $out;
  }

  // -------------------------------------------------------------- writes ---

  /** @return WP_REST_Response|WP_Error */
  public function save( WP_REST_Request $request ) {
    $body = $request->get_json_params();
    if ( ! is_array( $body ) ) {
      $body = (array) $request->get_body_params();
    }

    $id = sanitize_text_field( self::scalar( $body['id'] ?? '' ) );

    if ( strncmp( $id, 'fluent:', 7 ) === 0 ) {
      // Store::save() already no-ops on this prefix, but a silent no-op would
      // let the client show "saved" for a save that did nothing.
      return new WP_Error(
        'facr_readonly',
        __( "Fluent's global rates are read-only here; edit them in Fluent Affiliate's WooCommerce settings.", 'fa-commission-rules' ),
        [ 'status' => 403 ]
      );
    }

    $existing = $id !== '' ? Store::get( $id ) : null;
    if ( $id !== '' && ! $existing ) {
      // Deleted between the editor opening and this submit. Recreating it under
      // the id the browser still holds would silently resurrect stale data.
      return new WP_Error( 'facr_missing', __( 'That rule no longer exists.', 'fa-commission-rules' ), [ 'status' => 404 ] );
    }

    $target_ids = [];
    foreach ( (array) ( $body['target_ids'] ?? [] ) as $target_id ) {
      $target_ids[] = (int) self::scalar( $target_id );
    }

    $input = [
      'id'          => $id,
      // Never from the request: created_at decides the newest-wins tie-break,
      // i.e. real money. An edit keeps its stamp; a new rule is stamped by Store.
      'created_at'  => (string) ( $existing['created_at'] ?? '' ),
      'status'      => sanitize_key( self::scalar( $body['status'] ?? 'active' ) ),
      'scope_type'  => sanitize_key( self::scalar( $body['scope_type'] ?? 'all' ) ),
      'scope_id'    => (int) self::scalar( $body['scope_id'] ?? 0 ),
      'target_type' => sanitize_key( self::scalar( $body['target_type'] ?? 'all' ) ),
      'target_ids'  => $target_ids,
      'rate'        => sanitize_text_field( self::scalar( $body['rate'] ?? '' ) ),
      'rate_type'   => sanitize_key( self::scalar( $body['rate_type'] ?? 'percentage' ) ),
      'starts_at'   => sanitize_text_field( self::scalar( $body['starts_at'] ?? '' ) ),
      'ends_at'     => sanitize_text_field( self::scalar( $body['ends_at'] ?? '' ) ),
      'note'        => sanitize_text_field( self::scalar( $body['note'] ?? '' ) ),
    ];

    [ $rule, $errors ] = Store::validate( $input );
    if ( $errors ) {
      return new WP_REST_Response( [ 'errors' => $errors ], 422 );
    }

    Store::save( $rule );

    // Non-blocking: the rule is saved either way, but an equally specific rival
    // means the only thing deciding real money is which one is newer.
    $ties = Resolver::tie_map( array_merge( Store::all(), Store::fluent_global_rules() ) );

    return new WP_REST_Response(
      [
        'rule' => $this->present( $rule ),
        'tie'  => array_values( (array) ( $ties[ (string) $rule['id'] ] ?? [] ) ),
      ],
      $id === '' ? 201 : 200
    );
  }

  /** @return WP_REST_Response|WP_Error */
  public function delete( WP_REST_Request $request ) {
    $id = sanitize_text_field( self::scalar( $request->get_param( 'id' ) ) );
    if ( strncmp( $id, 'fluent:', 7 ) === 0 ) {
      return new WP_Error(
        'facr_readonly',
        __( "Fluent's global rates are read-only here; edit them in Fluent Affiliate's WooCommerce settings.", 'fa-commission-rules' ),
        [ 'status' => 403 ]
      );
    }
    if ( ! Store::delete( $id ) ) {
      return new WP_Error( 'facr_missing', __( 'That rule no longer exists.', 'fa-commission-rules' ), [ 'status' => 404 ] );
    }
    return new WP_REST_Response( [ 'deleted' => $id ] );
  }

  /** @return WP_REST_Response|WP_Error */
  public function bulk( WP_REST_Request $request ) {
    $body = $request->get_json_params();
    if ( ! is_array( $body ) ) {
      $body = (array) $request->get_body_params();
    }

    $action = sanitize_key( self::scalar( $body['action'] ?? '' ) );
    if ( ! in_array( $action, [ 'activate', 'deactivate', 'delete' ], true ) ) {
      return new WP_Error( 'facr_bad_action', __( 'Unknown bulk action.', 'fa-commission-rules' ), [ 'status' => 400 ] );
    }

    $ids = [];
    foreach ( (array) ( $body['ids'] ?? [] ) as $raw ) {
      $id = sanitize_text_field( self::scalar( $raw ) );
      if ( $id !== '' && strncmp( $id, 'fluent:', 7 ) !== 0 ) {
        $ids[] = $id;
      }
    }

    $count = 0;
    if ( $ids ) {
      if ( $action === 'activate' ) {
        $count = Store::set_status( $ids, 'active' );
      } elseif ( $action === 'deactivate' ) {
        $count = Store::set_status( $ids, 'inactive' );
      } else {
        $count = Store::delete_many( $ids );
      }
    }

    return new WP_REST_Response(
      [
        'count'   => $count,
        /* translators: %d: number of rules changed by a bulk action. */
        'message' => sprintf( _n( '%d rule updated.', '%d rules updated.', $count, 'fa-commission-rules' ), $count ),
      ]
    );
  }
}
