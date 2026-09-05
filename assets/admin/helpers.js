/**
 * Pure helpers for the Commission Rules admin app. No DOM, no Vue, no
 * network: everything here runs under `node --test` (tests/helpers.test.mjs)
 * and in the browser as window.facrHelpers.
 */
( function ( root, factory ) {
  'use strict';
  var api = factory();
  if ( typeof module !== 'undefined' && module.exports ) {
    module.exports = api;
  }
  if ( root ) {
    root.facrHelpers = api;
  }
} )( typeof window !== 'undefined' ? window : null, function () {
  'use strict';

  var SCOPE_RANK  = { affiliate: 3, group: 2, all: 1 };
  var TARGET_RANK = { product: 3, category: 2, all: 1 };

  /** PHP-style sprintf for the localised templates: %s, %d, %1$s, %2$d. */
  function sprintf( template ) {
    var args = Array.prototype.slice.call( arguments, 1 );
    var next = 0;
    return String( template ).replace( /%(?:(\d+)\$)?([sd])/g, function ( match, position, type ) {
      var value = position ? args[ Number( position ) - 1 ] : args[ next++ ];
      if ( value === undefined || value === null ) {
        value = '';
      }
      return type === 'd' ? String( parseInt( value, 10 ) || 0 ) : String( value );
    } );
  }

  function specificity( rule ) {
    return ( SCOPE_RANK[ rule.scope_type ] || 1 ) * 10 + ( TARGET_RANK[ rule.target_type ] || 1 );
  }

  /** Most specific first, so the rules that actually fire are on top; newest breaks ties. */
  function sortRules( rules ) {
    return rules.slice().sort( function ( a, b ) {
      var byScore = specificity( b ) - specificity( a );
      if ( byScore !== 0 ) {
        return byScore;
      }
      // Code-point compare, not localeCompare(): PHP's strcmp (used server-side
      // for the same ordering) is byte-wise, and localeCompare would rank a
      // microsecond timestamp behind a same-second one with no fraction at all.
      var ac = String( a.created_at || '' );
      var bc = String( b.created_at || '' );
      return ac < bc ? 1 : ( ac > bc ? -1 : 0 );
    } );
  }

  function filterRules( rules, filters ) {
    filters = filters || {};
    var q = String( filters.q || '' ).trim().toLowerCase();
    return rules.filter( function ( rule ) {
      if ( filters.scope && rule.scope_type !== filters.scope ) {
        return false;
      }
      if ( filters.target && rule.target_type !== filters.target ) {
        return false;
      }
      if ( filters.status && rule.status !== filters.status ) {
        return false;
      }
      if ( ! q ) {
        return true;
      }
      var labels = rule.labels || {};
      var haystack = [ labels.target, labels.scope, rule.note, labels.sentence ].join( ' ' ).toLowerCase();
      return haystack.indexOf( q ) !== -1;
    } );
  }

  /**
   * The status-column badge. Both maps are computed server-side on the WHOLE
   * list, so a rule hidden by the current filter still shadows this one.
   */
  function badgeFor( rule, shadow, tie, byId, i18n ) {
    var none = { kind: null, text: '' };
    if ( ! rule || rule.readonly || rule.status !== 'active' ) {
      return none;
    }
    var ties = ( tie && tie[ rule.id ] ) || [];
    if ( ties.length ) {
      return { kind: 'tie', text: i18n.badge_tie };
    }
    var by = shadow ? shadow[ rule.id ] : null;
    if ( ! by ) {
      return none;
    }
    var other  = byId ? byId[ by ] : null;
    var labels = ( other && other.labels ) || {};
    var who    = other ? sprintf( i18n.badge_shadow_by, labels.scope || '', labels.target || '' ) : '';
    return { kind: 'shadow', text: sprintf( i18n.badge_shadow, who ) };
  }

  /** Mirrors Labels::rate_label(): trailing zeros trimmed, flat amounts in store money. */
  function formatRate( rate, rateType, ctx, i18n ) {
    var number = Number( rate );
    if ( ! isFinite( number ) ) {
      number = 0;
    }
    if ( rateType === 'flat' ) {
      var amount = number.toFixed( 2 ).replace( '.', ( ctx && ctx.decimal_separator ) || '.' );
      // ponytail: no thousands separator — a flat per-line commission is rarely
      // four digits, and the server's label is what the list shows anyway.
      return sprintf( i18n.flat_suffix, sprintf( ( ctx && ctx.money_template ) || '%s', amount ) );
    }
    return String( parseFloat( number.toFixed( 2 ) ) ) + '%';
  }

  /** The live "Result:" line in the editor; mirrors Labels::describe() in shape. */
  function sentence( form, ctx, i18n ) {
    ctx = ctx || {};
    var who;
    if ( form.scope_type === 'group' ) {
      who = ctx.scope_name || i18n.sentence_group;
    } else if ( form.scope_type === 'affiliate' ) {
      who = ctx.scope_name || i18n.sentence_affiliate;
    } else {
      who = i18n.sentence_everyone;
    }

    var what;
    var names = ( ctx.target_names || [] ).filter( Boolean );
    if ( form.target_type === 'all' ) {
      what = i18n.sentence_all_products;
    } else if ( names.length ) {
      what = names.join( ', ' );
    } else {
      what = form.target_type === 'category' ? i18n.sentence_category : i18n.sentence_product;
    }

    var when = '';
    if ( form.starts_at && form.ends_at ) {
      when = sprintf( i18n.sentence_between, form.starts_at, form.ends_at );
    } else if ( form.starts_at ) {
      when = sprintf( i18n.sentence_from, form.starts_at );
    } else if ( form.ends_at ) {
      when = sprintf( i18n.sentence_until, form.ends_at );
    }

    return sprintf( i18n.sentence, who, formatRate( form.rate, form.rate_type, ctx, i18n ), what ) + when;
  }

  function pad( n ) {
    return ( n < 10 ? '0' : '' ) + n;
  }

  function ymd( date ) {
    return date.getFullYear() + '-' + pad( date.getMonth() + 1 ) + '-' + pad( date.getDate() );
  }

  /** Parses a 'YYYY-MM-DD' string into a local Date; avoids the UTC shift `new Date(string)` does. */
  function parseYmd( value ) {
    var parts = String( value ).split( '-' );
    return new Date( Number( parts[ 0 ] ), Number( parts[ 1 ] ) - 1, Number( parts[ 2 ] ) );
  }

  /** Today through the day before the anniversary, in local calendar time. */
  function presetFirst12Months( today ) {
    var start = today instanceof Date
      ? new Date( today.getFullYear(), today.getMonth(), today.getDate() )
      : parseYmd( today );
    var end = new Date( start.getFullYear() + 1, start.getMonth(), start.getDate() );
    end.setDate( end.getDate() - 1 );
    return { starts_at: ymd( start ), ends_at: ymd( end ) };
  }

  /**
   * The status-column badge state, independent of the shadow/tie note.
   * 'YYYY-MM-DD' string comparison is safe: the format is fixed-width.
   */
  function statusOf( rule, today ) {
    if ( ! rule || rule.status !== 'active' ) {
      return 'inactive';
    }
    if ( rule.starts_at && rule.starts_at > today ) {
      return 'scheduled';
    }
    if ( rule.ends_at && rule.ends_at < today ) {
      return 'expired';
    }
    return 'effective';
  }

  return {
    sprintf: sprintf,
    specificity: specificity,
    sortRules: sortRules,
    filterRules: filterRules,
    badgeFor: badgeFor,
    formatRate: formatRate,
    sentence: sentence,
    presetFirst12Months: presetFirst12Months,
    parseYmd: parseYmd,
    statusOf: statusOf,
    ymd: ymd
  };
} );
