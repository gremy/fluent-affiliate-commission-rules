/**
 * Commission Rules admin app. Mounts on Fluent Affiliate's #fluent-framework-app
 * (printed by their AdminMenuHandler::render()) and talks to fa-commission-rules/v1.
 * Plain script, no build step: Vue, Element Plus, facrHelpers and facrAdmin are
 * globals registered by includes/Admin/Menu.php. Every visible string is i18n.*.
 */
( function () {
  'use strict';

  var cfg   = window.facrAdmin || {};
  var i18n  = cfg.i18n || {};
  var H     = window.facrHelpers;
  var EP    = window.ElementPlus;
  var mount = document.getElementById( 'fluent-framework-app' );

  if ( ! mount || ! window.Vue || ! EP || ! H ) {
    return;
  }

  var MONEY = { money_template: cfg.money_template || '%s', decimal_separator: cfg.decimal_separator || '.' };

  /** wp_localize_script casts top-level scalars to strings: '1' means true. */
  function flag( value ) {
    return value === true || value === 1 || value === '1';
  }

  /** rest_url() may be a ?rest_route= URL on plain permalinks, so build with URL. */
  function restUrl( path, query ) {
    var url = new URL( cfg.rest_url + path, window.location.origin );
    Object.keys( query || {} ).forEach( function ( key ) {
      url.searchParams.set( key, query[ key ] );
    } );
    return url.toString();
  }

  /** fetch() with the wp_rest nonce; resolves with parsed JSON, rejects with {status, data}. */
  function api( path, options ) {
    options = options || {};
    var headers = { 'X-WP-Nonce': cfg.nonce, Accept: 'application/json' };
    var init    = { method: options.method || 'GET', credentials: 'same-origin', headers: headers };
    if ( options.body !== undefined ) {
      headers[ 'Content-Type' ] = 'application/json';
      init.body = JSON.stringify( options.body );
    }
    return window.fetch( restUrl( path, options.query ), init ).then( function ( response ) {
      return response.json().catch( function () {
        return {};
      } ).then( function ( data ) {
        if ( response.ok ) {
          return data;
        }
        var error = new Error( ( data && data.message ) || i18n.error_generic );
        error.status = response.status;
        error.data   = data || {};
        throw error;
      } );
    } );
  }

  function notify( type, message ) {
    EP.ElNotification( { type: type, message: message, duration: 4000, offset: 40 } );
  }

  /** Resolves true/false; never rejects. */
  function confirm( message ) {
    return EP.ElMessageBox.confirm( message, i18n.confirm_title, {
      confirmButtonText: i18n.confirm_ok,
      cancelButtonText: i18n.confirm_cancel,
      type: 'warning'
    } ).then( function () {
      return true;
    }, function () {
      return false;
    } );
  }

  var LIST_TEMPLATE = [
    '<div class="fa-affiliate-wrap facr-app" v-loading="loading">',
    '  <div class="fa_page_heading"><h1 class="fa_page_title">{{ i18n.page_title }}</h1></div>',
    '  <div class="fa-affiliate-body">',
    '    <div class="fa-affiliate-body-actions-bar">',
    '      <div class="facr-filters">',
    '        <el-select v-model="filters.scope" :placeholder="i18n.filter_all_audiences" style="width:150px">',
    '          <el-option value="" :label="i18n.filter_all_audiences"></el-option>',
    '          <el-option value="affiliate" :label="i18n.filter_affiliate"></el-option>',
    '          <el-option value="group" :label="i18n.filter_group"></el-option>',
    '          <el-option value="all" :label="i18n.filter_everyone"></el-option>',
    '        </el-select>',
    '        <el-select v-model="filters.target" :placeholder="i18n.filter_any_target" style="width:150px">',
    '          <el-option value="" :label="i18n.filter_any_target"></el-option>',
    '          <el-option value="product" :label="i18n.filter_product"></el-option>',
    '          <el-option value="category" :label="i18n.filter_category"></el-option>',
    '          <el-option value="all" :label="i18n.filter_all_products"></el-option>',
    '        </el-select>',
    '        <el-select v-model="filters.status" :placeholder="i18n.filter_any_status" style="width:130px">',
    '          <el-option value="" :label="i18n.filter_any_status"></el-option>',
    '          <el-option value="active" :label="i18n.filter_active"></el-option>',
    '          <el-option value="inactive" :label="i18n.filter_inactive"></el-option>',
    '        </el-select>',
    '        <el-input v-model="filters.q" clearable :placeholder="i18n.search_placeholder" style="width:220px"></el-input>',
    '      </div>',
    '      <el-button type="primary" @click="openEditor()">{{ i18n.add_rule }}</el-button>',
    '    </div>',
    '    <div class="fa-affiliate-body-actions-bar facr-bulk-bar" v-if="selected.length">',
    '      <span>{{ selectedText }}</span>',
    '      <span>',
    '        <el-button size="small" @click="bulk(\'activate\')">{{ i18n.activate }}</el-button>',
    '        <el-button size="small" @click="bulk(\'deactivate\')">{{ i18n.deactivate }}</el-button>',
    '        <el-button size="small" type="danger" plain @click="bulk(\'delete\')">{{ i18n.delete }}</el-button>',
    '      </span>',
    '    </div>',
    '    <div class="fa_empty_state" v-if="!loading && !rules.length">',
    '      <el-empty :description="emptyText">',
    '        <el-button type="primary" @click="openEditor()">{{ i18n.add_first }}</el-button>',
    '      </el-empty>',
    '    </div>',
    '    <div class="fa_table_wrap" v-else>',
    '      <el-table :data="visibleRules" row-key="id" :empty-text="i18n.no_match" @selection-change="onSelect" style="width:100%">',
    '        <el-table-column type="selection" width="44" :selectable="selectable"></el-table-column>',
    '        <el-table-column :label="i18n.col_who" prop="labels.scope" min-width="180"></el-table-column>',
    '        <el-table-column :label="i18n.col_what" prop="labels.target" min-width="200"></el-table-column>',
    '        <el-table-column :label="i18n.col_rate" prop="labels.rate" width="110"></el-table-column>',
    '        <el-table-column :label="i18n.col_window" prop="labels.window" min-width="150"></el-table-column>',
    '        <el-table-column :label="i18n.col_status" min-width="220">',
    '          <template #default="{ row }">',
    '            <span class="fa_badge" :class="statusClass(row)">{{ statusText(row) }}</span>',
    '            <div v-if="badge(row).kind" class="facr-badge-note">{{ badge(row).text }}</div>',
    '          </template>',
    '        </el-table-column>',
    '        <el-table-column :label="i18n.col_note" prop="note" min-width="160"></el-table-column>',
    '        <el-table-column :label="i18n.col_actions" width="150" align="right">',
    '          <template #default="{ row }">',
    '            <template v-if="!row.readonly">',
    '              <el-button link type="primary" size="small" @click="openEditor(row)">{{ i18n.edit }}</el-button>',
    '              <el-button link type="danger" size="small" @click="remove(row)">{{ i18n.delete }}</el-button>',
    '            </template>',
    '            <el-tooltip v-else :content="i18n.readonly_hint" placement="top">',
    '              <span class="facr-readonly">{{ i18n.readonly_short }}</span>',
    '            </el-tooltip>',
    '          </template>',
    '        </el-table-column>',
    '      </el-table>',
    '    </div>',
    '  </div>',
    '  <!-- facr:editor -->',
    '</div>'
  ].join( '\n' );

  var app = window.Vue.createApp( {
    template: LIST_TEMPLATE,

    data: function () {
      return {
        i18n: i18n,
        loading: true,
        rules: [],
        shadow: {},
        tie: {},
        defaultRate: cfg.default_rate || '',
        options: { affiliates: [], groups: [], categories: [], has_pro: flag( cfg.has_pro ), has_woo: flag( cfg.has_woo ) },
        filters: { scope: '', target: '', status: '', q: '' },
        selected: []
        // facr:editor-data
      };
    },

    computed: {
      byId: function () {
        var map = {};
        this.rules.forEach( function ( rule ) {
          map[ rule.id ] = rule;
        } );
        return map;
      },
      visibleRules: function () {
        return H.sortRules( H.filterRules( this.rules, this.filters ) );
      },
      emptyText: function () {
        return H.sprintf( i18n.empty_body, this.defaultRate );
      },
      selectedText: function () {
        return H.sprintf( i18n.selected_count, this.selected.length );
      }
      // facr:editor-computed
    },

    created: function () {
      this.load();
      this.loadOptions();
    },

    methods: {
      load: function () {
        var vm = this;
        vm.loading = true;
        return api( '/rules' ).then( function ( data ) {
          vm.rules       = data.rules || [];
          vm.shadow      = data.shadow || {};
          vm.tie         = data.tie || {};
          vm.defaultRate = data.default_rate || vm.defaultRate;
          vm.loading     = false;
        }, function ( error ) {
          vm.loading = false;
          notify( 'error', error.message );
        } );
      },
      loadOptions: function () {
        var vm = this;
        return api( '/options' ).then( function ( data ) {
          vm.options = {
            affiliates: data.affiliates || [],
            groups: data.groups || [],
            categories: data.categories || [],
            has_pro: !! data.has_pro,
            has_woo: !! data.has_woo
          };
          vm.defaultRate = data.default_rate || vm.defaultRate;
        }, function ( error ) {
          notify( 'error', error.message );
        } );
      },
      selectable: function ( row ) {
        return ! row.readonly;
      },
      onSelect: function ( rows ) {
        this.selected = rows.filter( function ( row ) {
          return ! row.readonly;
        } );
      },
      badge: function ( row ) {
        return H.badgeFor( row, this.shadow, this.tie, this.byId, i18n );
      },
      statusClass: function ( row ) {
        if ( row.readonly ) {
          return 'woo';
        }
        if ( row.status !== 'active' ) {
          return 'inactive';
        }
        return this.badge( row ).kind ? 'warning' : 'success';
      },
      statusText: function ( row ) {
        if ( row.readonly ) {
          return i18n.status_global;
        }
        return row.status === 'active' ? i18n.status_effective : i18n.status_inactive;
      },
      remove: function ( row ) {
        var vm = this;
        confirm( H.sprintf( i18n.confirm_delete_one, vm.defaultRate ) ).then( function ( ok ) {
          if ( ! ok ) {
            return;
          }
          api( '/rules/' + encodeURIComponent( row.id ), { method: 'DELETE' } ).then( function () {
            notify( 'success', i18n.deleted );
            vm.load();
          }, function ( error ) {
            notify( 'error', error.message );
          } );
        } );
      },
      bulk: function ( action ) {
        var vm  = this;
        var ids = vm.selected.map( function ( row ) {
          return row.id;
        } );
        if ( ! ids.length ) {
          return;
        }
        var ask = Promise.resolve( true );
        if ( action === 'delete' ) {
          ask = confirm( H.sprintf( i18n.confirm_delete_many, vm.defaultRate ) );
        } else if ( action === 'deactivate' ) {
          ask = confirm( H.sprintf( i18n.confirm_deactivate, vm.defaultRate ) );
        }
        ask.then( function ( ok ) {
          if ( ! ok ) {
            return;
          }
          api( '/rules/bulk', { method: 'POST', body: { action: action, ids: ids } } ).then( function ( data ) {
            var count = Number( data.count ) || 0;
            notify( 'success', H.sprintf( count === 1 ? i18n.bulk_done_one : i18n.bulk_done_many, count ) );
            vm.selected = [];
            vm.load();
          }, function ( error ) {
            notify( 'error', error.message );
          } );
        } );
      },
      openEditor: function ( rule ) {
        // Task 8 replaces this stub with the drawer; until then the button is inert.
        return rule;
      }
      // facr:editor-methods
    }
  } );

  app.use( EP );
  app.mount( mount );
} )();
