import { test } from 'node:test';
import assert from 'node:assert/strict';
import { createRequire } from 'node:module';
import { fileURLToPath } from 'node:url';
import path from 'node:path';

const require = createRequire( import.meta.url );
const here = path.dirname( fileURLToPath( import.meta.url ) );
const H = require( path.join( here, '..', 'assets', 'admin', 'helpers.js' ) );

const i18n = {
  badge_shadow: 'Overridden for some products by: %s',
  badge_shadow_by: '%1$s → %2$s',
  badge_tie: 'Conflict: two rules are equally specific',
  sentence: '%1$s earns %2$s on %3$s.',
  sentence_from: ' From %s.',
  sentence_until: ' Until %s.',
  sentence_between: ' From %1$s to %2$s.',
  sentence_everyone: 'Everyone',
  sentence_group: 'This group',
  sentence_affiliate: 'This affiliate',
  sentence_all_products: 'all products',
  sentence_category: 'the chosen categories',
  sentence_product: 'the chosen products',
  flat_suffix: '%s flat'
};
const ctx = { money_template: '$ %s', decimal_separator: '.', scope_name: '', target_names: [] };

function rule( overrides ) {
  return Object.assign( {
    id: 'r', status: 'active', scope_type: 'all', scope_id: 0, target_type: 'all', target_ids: [],
    rate: 10, rate_type: 'percentage', starts_at: '', ends_at: '', note: '', created_at: '2026-01-01T00:00:00+00:00',
    readonly: false,
    labels: { scope: 'Everyone', target: 'All products', rate: '10%', window: 'Always', sentence: '10% on All products (Always)' },
    target_options: []
  }, overrides );
}

test( 'sprintf handles %s, %d and positional arguments', () => {
  assert.equal( H.sprintf( '%s selected', 3 ), '3 selected' );
  assert.equal( H.sprintf( '%d rules', '7' ), '7 rules' );
  assert.equal( H.sprintf( '%2$s then %1$s', 'a', 'b' ), 'b then a' );
  assert.equal( H.sprintf( 'no placeholders' ), 'no placeholders' );
} );

test( 'specificity ranks scope over target', () => {
  assert.equal( H.specificity( rule() ), 11 );
  assert.equal( H.specificity( rule( { scope_type: 'group', target_type: 'category' } ) ), 22 );
  assert.equal( H.specificity( rule( { scope_type: 'affiliate', target_type: 'product' } ) ), 33 );
  assert.ok( H.specificity( rule( { scope_type: 'group', target_type: 'product' } ) ) < H.specificity( rule( { scope_type: 'affiliate', target_type: 'all' } ) ) );
} );

test( 'sortRules puts the most specific first, then the newest, without mutating', () => {
  const a = rule( { id: 'a', scope_type: 'all', created_at: '2026-01-01T00:00:00+00:00' } );
  const b = rule( { id: 'b', scope_type: 'affiliate', target_type: 'product', created_at: '2026-01-01T00:00:00+00:00' } );
  const c = rule( { id: 'c', scope_type: 'all', created_at: '2026-02-01T00:00:00+00:00' } );
  const input = [ a, b, c ];
  const sorted = H.sortRules( input );
  assert.deepEqual( sorted.map( r => r.id ), [ 'b', 'c', 'a' ] );
  assert.deepEqual( input.map( r => r.id ), [ 'a', 'b', 'c' ] );
} );

test( 'filterRules matches scope, target, status and a case-insensitive query', () => {
  const rules = [
    rule( { id: '1', scope_type: 'affiliate', labels: { scope: 'Affiliate: Jane Doe (#12)', target: 'All products', rate: '10%', window: 'Always', sentence: '' }, note: 'Year-1 deal' } ),
    rule( { id: '2', scope_type: 'group', target_type: 'category', status: 'inactive', labels: { scope: 'Group: Gold', target: 'Category: Coffee', rate: '5%', window: 'Always', sentence: '' } } ),
    rule( { id: '3', readonly: true } )
  ];
  assert.deepEqual( H.filterRules( rules, { scope: '', target: '', status: '', q: '' } ).map( r => r.id ), [ '1', '2', '3' ] );
  assert.deepEqual( H.filterRules( rules, { scope: 'group' } ).map( r => r.id ), [ '2' ] );
  assert.deepEqual( H.filterRules( rules, { target: 'all' } ).map( r => r.id ), [ '1', '3' ] );
  assert.deepEqual( H.filterRules( rules, { status: 'inactive' } ).map( r => r.id ), [ '2' ] );
  assert.deepEqual( H.filterRules( rules, { q: 'jane' } ).map( r => r.id ), [ '1' ] );
  assert.deepEqual( H.filterRules( rules, { q: 'COFFEE' } ).map( r => r.id ), [ '2' ] );
  assert.deepEqual( H.filterRules( rules, { q: 'year-1' } ).map( r => r.id ), [ '1' ] );
  assert.deepEqual( H.filterRules( rules, { q: 'nothing here' } ), [] );
} );

test( 'badgeFor reports a shadow with the narrower rule named', () => {
  const wide = rule( { id: 'wide' } );
  const narrow = rule( { id: 'narrow', scope_type: 'affiliate', target_type: 'product', labels: { scope: 'Affiliate: Jane (#1)', target: 'Product: Beans', rate: '', window: '', sentence: '' } } );
  const byId = { wide, narrow };
  const badge = H.badgeFor( wide, { wide: 'narrow', narrow: null }, { wide: [], narrow: [] }, byId, i18n );
  assert.equal( badge.kind, 'shadow' );
  assert.equal( badge.text, 'Overridden for some products by: Affiliate: Jane (#1) → Product: Beans' );
} );

test( 'badgeFor prefers a tie, and stays silent for inactive, read-only and unshadowed rules', () => {
  const a = rule( { id: 'a' } );
  const b = rule( { id: 'b' } );
  const byId = { a, b };
  assert.deepEqual( H.badgeFor( a, { a: 'b' }, { a: [ 'b' ] }, byId, i18n ), { kind: 'tie', text: i18n.badge_tie } );
  assert.deepEqual( H.badgeFor( rule( { id: 'a', status: 'inactive' } ), { a: 'b' }, { a: [ 'b' ] }, byId, i18n ), { kind: null, text: '' } );
  assert.deepEqual( H.badgeFor( rule( { id: 'a', readonly: true } ), { a: 'b' }, { a: [] }, byId, i18n ), { kind: null, text: '' } );
  assert.deepEqual( H.badgeFor( a, { a: null }, { a: [] }, byId, i18n ), { kind: null, text: '' } );
  assert.deepEqual( H.badgeFor( a, {}, {}, {}, i18n ), { kind: null, text: '' } );
} );

test( 'formatRate mirrors Labels::rate_label', () => {
  assert.equal( H.formatRate( 12.5, 'percentage', ctx, i18n ), '12.5%' );
  assert.equal( H.formatRate( '10', 'percentage', ctx, i18n ), '10%' );
  assert.equal( H.formatRate( 10.126, 'percentage', ctx, i18n ), '10.13%' );
  assert.equal( H.formatRate( 3, 'flat', ctx, i18n ), '$ 3.00 flat' );
  assert.equal( H.formatRate( 2.5, 'flat', { money_template: '€ %s', decimal_separator: ',' }, i18n ), '€ 2,50 flat' );
  assert.equal( H.formatRate( '', 'percentage', ctx, i18n ), '0%' );
} );

test( 'sentence builds the live result line', () => {
  const base = { scope_type: 'all', scope_id: 0, target_type: 'all', target_ids: [], rate: 10, rate_type: 'percentage', starts_at: '', ends_at: '' };
  assert.equal( H.sentence( base, ctx, i18n ), 'Everyone earns 10% on all products.' );
  assert.equal(
    H.sentence( Object.assign( {}, base, { scope_type: 'affiliate', scope_id: 12, target_type: 'product', target_ids: [ 1, 2 ] } ), Object.assign( {}, ctx, { scope_name: 'Jane Doe (#12)', target_names: [ 'Beans', 'Grinder' ] } ), i18n ),
    'Jane Doe (#12) earns 10% on Beans, Grinder.'
  );
  assert.equal( H.sentence( Object.assign( {}, base, { scope_type: 'group', scope_id: 3 } ), ctx, i18n ), 'This group earns 10% on all products.' );
  assert.equal( H.sentence( Object.assign( {}, base, { scope_type: 'affiliate', scope_id: 0 } ), ctx, i18n ), 'This affiliate earns 10% on all products.' );
  assert.equal( H.sentence( Object.assign( {}, base, { target_type: 'category', target_ids: [ 5 ] } ), ctx, i18n ), 'Everyone earns 10% on the chosen categories.' );
  assert.equal( H.sentence( Object.assign( {}, base, { rate: 3, rate_type: 'flat' } ), ctx, i18n ), 'Everyone earns $ 3.00 flat on all products.' );
  assert.equal( H.sentence( Object.assign( {}, base, { starts_at: '2026-09-05' } ), ctx, i18n ), 'Everyone earns 10% on all products. From 2026-09-05.' );
  assert.equal( H.sentence( Object.assign( {}, base, { ends_at: '2027-09-04' } ), ctx, i18n ), 'Everyone earns 10% on all products. Until 2027-09-04.' );
  assert.equal( H.sentence( Object.assign( {}, base, { starts_at: '2026-09-05', ends_at: '2027-09-04' } ), ctx, i18n ), 'Everyone earns 10% on all products. From 2026-09-05 to 2027-09-04.' );
} );

test( 'presetFirst12Months ends the day before the anniversary', () => {
  assert.deepEqual( H.presetFirst12Months( '2026-09-05' ), { starts_at: '2026-09-05', ends_at: '2027-09-04' } );
  assert.deepEqual( H.presetFirst12Months( '2024-02-29' ), { starts_at: '2024-02-29', ends_at: '2025-02-28' } );
  assert.deepEqual( H.presetFirst12Months( '2026-01-01' ), { starts_at: '2026-01-01', ends_at: '2026-12-31' } );
  assert.deepEqual( H.presetFirst12Months( new Date( 2026, 8, 5 ) ), { starts_at: '2026-09-05', ends_at: '2027-09-04' } );
} );

test( 'ymd zero-pads', () => {
  assert.equal( H.ymd( new Date( 2026, 0, 7 ) ), '2026-01-07' );
} );
