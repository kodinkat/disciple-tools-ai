(function() {
  "use strict";
  jQuery(document).ready(function() {

    // expand the current selected menu
    jQuery('#metrics-sidemenu').foundation('down', jQuery(`#${window.dt_mapbox_metrics.settings.menu_slug}-menu`));

    window.mapbox_library_api = {
      container_set_up: false,
      current_map_type: 'points',
      obj: window.dt_mapbox_metrics,
      post_type: window.dt_mapbox_metrics.settings.post_type,
      title: window.dt_mapbox_metrics.settings.title,
      map: null,
      setup_container: function() {
        if (this.container_set_up) {
          return;
        }
        if (typeof window.dt_mapbox_metrics.settings === 'undefined') {
          return;
        }

        let chart = jQuery('#chart');

        // Ensure a valid mapbox key has been specified.
        if (!window.dt_mapbox_metrics.settings.map_key) {
          chart.empty();
          let mapping_settings_url = window.wpApiShare.site_url + '/wp-admin/admin.php?page=dt_mapping_module&tab=geocoding';
          chart
          .empty()
          .html(
            `<a href="${window.lodash.escape(mapping_settings_url)}">${window.lodash.escape(window.dt_mapbox_metrics.settings.no_map_key_msg)}</a>`,
          );

          return;
        }

        chart.empty().html(`
          <style>
              #map-wrapper {
                  position: relative;
                  height: ${window.innerHeight - 100}px;
                  width:100%;
              }
              #map {
                  position: absolute;
                  top: 0;
                  left: 0;
                  z-index: 1;
                  width:100%;
                  height: ${window.innerHeight - 100}px;
              }

              /* ===== Search & Filter ===== */
              #search-filter {
                  --header-height: 0rem;
                  --search-margin-block: 1rem;
                  --search-border-width: 2px;
                  --search-input-height: 2.5rem;
                  --search-height: calc(
                      var(--search-margin-block) + var(--search-margin-block) +
                      var(--search-input-height) + 1rem
                  );

                  display: grid;
                  grid-template-rows: var(--search-height) 1fr;
                  transition: grid-template-rows 500ms;
                  z-index: 1;
                  position: absolute;
                  width: 100%;
                  top: var(--header-height);
              }
              #search-filter:not(:has(.filters)) {
                  border-bottom: 4px solid lightgray;
              }

              #search-filter:has(.filters.hidden) {
                  grid-template-rows: var(--search-height) 0fr;
              }

              #search-bar,
              .filters {
                  width: 100%;
              }
              #search-filter,
              #search-bar,
              .filters {
                  background: #fff;
              }
              #search-bar {
                  position: relative;
                  display: flex;
                  flex-wrap: wrap;
                  z-index: 1;
              }
              #search {
                  margin: var(--search-margin-block);
                  flex-grow: 1;
                  min-width: 1rem;
                  font-size: 1.25rem;
                  padding-left: 10px;
                  padding-right: 10px;
                  height: var(--search-input-height);
              }

              #search-bar button.clear-button {
                  position: absolute;
                  inset-inline-end: 4.25rem;
                  top: calc(var(--search-margin-block) + var(--search-border-width));
                  border: 0;
                  background-color: transparent;
                  height: var(--search-input-height);
              }

              #search-bar button.filter-button {
                  position: absolute;
                  inset-inline-end: 2rem;
                  top: calc(var(--search-margin-block) + var(--search-border-width));
                  border: 0;
                  background-color: transparent;
                  height: var(--search-input-height);
              }

              .filters .container {
                  padding: 1rem;
              }

              .filters {
                  border-bottom: 4px solid lightgray;
                  z-index: 1;
                  visibility: visible;
                  overflow: hidden;
                  transition: flex 1s ease;
              }
              .filters label {
                  display: inline-block;
                  font-weight: normal;
                  border: solid 1px lightgray;
                  padding: 5px 10px;
                  margin: 2px;
              }
              .filters label:has(input:checked) {
                  border-color: var(--primary-color);
                  border-width: 3px;
                  margin: 0;
              }
              .filters label input {
                  display: none;
              }
            </style>
            <div id="map-wrapper">
              <div id='map'></div>
              <div id="search-filter">
                <div id="search-bar">
                    <input type="text" id="search" placeholder="${window.lodash.escape(window.dt_mapbox_metrics.translations.placeholder)}" onkeyup="show_filter_clear_option();" />
                    <button id="clear-button" style="display: none;" class="clear-button mdi mdi-close" onclick="clear_filter();"></button>
                    <button class="filter-button" onclick="create_filter();">
                      <i id="filter_icon" class="mdi mdi-star-four-points-outline"></i>
                      <span id="filter_spinner" style="display: inline-block" class="loading-spinner active"></span>
                    </button>
                </div>
              </div>
              <div id="geocode-details" class="geocode-details">
                ${window.lodash.escape(window.dt_mapbox_metrics.translations.details_title)}<span class="close-details" style="float:right;"><i class="fi-x"></i></span>
                <hr style="margin:10px 5px;">
                <div id="geocode-details-content"></div>
              </div>
            </div>
        `);

        document.getElementById('filter_spinner').style.display = 'none';

        document.getElementById('search').addEventListener('keyup', function(e) {
          e.preventDefault();

          if (e.key === 'Enter') { // Enter key pressed.
            create_filter();
          }
        });

        mapbox_library_api.setup_map_type();
      },
      setup_map_type: function() {

        // init map
        window.mapboxgl.accessToken = this.obj.settings.map_key;
        if (mapbox_library_api.map) {
          mapbox_library_api.map.remove();
        }

        mapbox_library_api.map = new window.mapboxgl.Map({
          container: 'map',
          style: 'mapbox://styles/mapbox/light-v10',
          center: [2, 46],
          minZoom: 1,
          zoom: 1.8,
        });

        // SET BOUNDS
        let map_bounds_token = this.obj.settings.post_type + this.obj.settings.menu_slug;
        let map_start = window.get_map_start(map_bounds_token);
        if (map_start) {
          mapbox_library_api.map.fitBounds(map_start, { duration: 0 });
        }

        mapbox_library_api.map.on('zoomend', function () {
          window.set_map_start(
            map_bounds_token,
            mapbox_library_api.map.getBounds(),
          );
        });

        mapbox_library_api.map.on('dragend', function () {
          window.set_map_start(
            map_bounds_token,
            mapbox_library_api.map.getBounds(),
          );
        });

        // end set bounds
        // disable map rotation using right click + drag
        mapbox_library_api.map.dragRotate.disable();

        // disable map rotation using touch rotation gesture
        mapbox_library_api.map.touchZoomRotate.disableRotation();

        mapbox_library_api.map.on('load', function () {
          console.log('map loaded');
        });
      }
    };

    mapbox_library_api.setup_container();

    window.show_filter_clear_option = function show_filter_clear_option() {
      const text = document.getElementById('search').value;
      const clear_button = document.getElementById('clear-button');

      if (!text && clear_button.style.display === 'block') {
        clear_button.setAttribute('style', 'display: none;');

      } else if (text && clear_button.style.display === 'none') {
        clear_button.setAttribute('style', 'display: block;');
      }
    }

    window.clear_filter = function clear_filter() {
      document.getElementById('search').value = '';
    }

    window.create_filter = function create_filter() {
      const text = document.getElementById('search').value;

      if (!text) {
        return;
      }

      let payload = {
        "prompt": text,
        "post_type": mapbox_library_api.obj.settings.post_type
      };

      document.getElementById('filter_spinner').style.display = 'inline-block';
      document.getElementById('filter_icon').style.display = 'none';

      // Close any open post-record detail links and submit request.
      $('#geocode-details').fadeOut('fast');

      jQuery.ajax({
        type: "POST",
        data: JSON.stringify(payload),
        contentType: "application/json; charset=utf-8",
        dataType: "json",
        url: `${mapbox_library_api.obj.settings.rest_endpoints_base}/create_filter`,
        beforeSend: function(xhr) {
          xhr.setRequestHeader('X-WP-Nonce', mapbox_library_api.obj.settings.nonce);
        },
      })
      .done(function (data) {
        console.log(data);

        /**
         * Pause the flow accordingly, if multiple connection options are available.
         * If so, then display modal with connection options.
         */

        if (data?.status === 'error') {
          alert( (data?.message) ? data.message : `${window.lodash.escape(window.dt_mapbox_metrics.translations.default_error_msg)}` );

          document.getElementById('filter_spinner').style.display = 'none';
          document.getElementById('filter_icon').style.display = 'inline-block';

        } else if ((data?.status === 'multiple_options_detected') && (data?.multiple_options)) {
          window.show_multiple_options_modal(data.multiple_options, data?.pii, data?.fields);

        } else if ((data?.status === 'success') && (data?.points)) {
          window.load_points(data.points);

          document.getElementById('filter_spinner').style.display = 'none';
          document.getElementById('filter_icon').style.display = 'inline-block';
        }
      })
      .fail(function (err) {
        console.log('error')
        console.log(err)

        document.getElementById('filter_spinner').style.display = 'none';
        document.getElementById('filter_icon').style.display = 'inline-block';
      });
    }

    window.show_multiple_options_modal = (multiple_options, pii, filter_fields) => {
      const modal = $('#modal-small');
      if (modal) {

        $(modal).find('#modal-small-title').html(`${window.lodash.escape(window.dt_mapbox_metrics.translations.multiple_options.title)}`);

        /**
         * Location Options.
         */

        let locations_html = '';
        if (multiple_options?.locations && multiple_options.locations.length > 0) {

          locations_html += `
                <h4>${window.lodash.escape(window.dt_mapbox_metrics.translations.multiple_options.locations)}</h4>
                <table class="widefat striped">
                    <tbody class="locations">
              `;

          multiple_options.locations.forEach((location) => {
            if (location?.prompt && location?.options) {
              locations_html += `
                    <tr>
                      <td style="vertical-align: top;">
                        ${window.lodash.escape(location.prompt)}
                        <input class="prompt" type="hidden" value="${location.prompt}" />
                      </td>
                      <td>
                        <select class="options">`;

              locations_html += `<option value="ignore">${window.lodash.escape(window.dt_mapbox_metrics.translations.multiple_options.ignore_option)}</option>`;

              location.options.forEach((option) => {
                if (option?.id && option?.label) {
                  locations_html += `<option value="${window.lodash.escape(option.id)}">${window.lodash.escape(option.label)}</option>`;
                }
              });

              locations_html += `</select>
                      </td>
                    </tr>
                `;
            }
          });

          locations_html += `
                    </tbody>
                </table>
              `;
        }

        /**
         * User Options.
         */

        let users_html = '';
        if (multiple_options?.users && multiple_options.users.length > 0) {

          users_html += `
                <h4>${window.lodash.escape(window.dt_mapbox_metrics.translations.multiple_options.users)}</h4>
                <table class="widefat striped">
                    <tbody class="users">
              `;

          multiple_options.users.forEach((user) => {
            if (user?.prompt && user?.options) {
              users_html += `
                    <tr>
                      <td style="vertical-align: top;">
                        ${window.lodash.escape(user.prompt)}
                        <input class="prompt" type="hidden" value="${user.prompt}" />
                      </td>
                      <td>
                        <select class="options">`;

              users_html += `<option value="ignore">${window.lodash.escape(window.dt_mapbox_metrics.translations.multiple_options.ignore_option)}</option>`;

              user.options.forEach((option) => {
                if (option?.id && option?.label) {
                  users_html += `<option value="${window.lodash.escape(option.id)}">${window.lodash.escape(option.label)}</option>`;
                }
              });

              users_html += `</select>
                      </td>
                    </tr>
                `;
            }
          });

          users_html += `
                    </tbody>
                </table>
              `;
        }

        /**
         * Post Options.
         */

        let posts_html = '';
        if (multiple_options?.posts && multiple_options.posts.length > 0) {

          posts_html += `
                <h4>${window.lodash.escape(window.dt_mapbox_metrics.translations.multiple_options.posts)}</h4>
                <table class="widefat striped">
                    <tbody class="posts">
              `;

          multiple_options.posts.forEach((post) => {
            if (post?.prompt && post?.options) {
              posts_html += `
                    <tr>
                      <td style="vertical-align: top;">
                        ${window.lodash.escape(post.prompt)}
                        <input class="prompt" type="hidden" value="${post.prompt}" />
                      </td>
                      <td>
                        <select class="options">`;

              posts_html += `<option value="ignore">${window.lodash.escape(window.dt_mapbox_metrics.translations.multiple_options.ignore_option)}</option>`;

              post.options.forEach((option) => {
                if (option?.id && option?.label) {
                  posts_html += `<option value="${window.lodash.escape(option.id)}">${window.lodash.escape(option.label)}</option>`;
                }
              });

              posts_html += `</select>
                      </td>
                    </tr>
                `;
            }
          });

          posts_html += `
                    </tbody>
                </table>
              `;
        }

        let html = `
                <br>
                ${locations_html}
                <br>
                ${users_html}
                <br>
                ${posts_html}
                <br>
                <button class="button" aria-label="submit" type="button" id="multiple_options_submit">
                    <span aria-hidden="true">${window.lodash.escape(window.dt_mapbox_metrics.translations.multiple_options.submit_but)}</span>
                </button>
                <button class="button" data-close aria-label="submit" type="button">
                    <span aria-hidden="true">${window.lodash.escape(window.dt_mapbox_metrics.translations.multiple_options.close_but)}</span>
                </button>
                <input id="multiple_options_filtered_fields" type="hidden" value="${encodeURIComponent( JSON.stringify(filter_fields) )}" />
                <input id="multiple_options_pii" type="hidden" value="${encodeURIComponent( JSON.stringify(pii) )}" />
            `;

        $(modal).find('#modal-small-content').html(html);

        $(modal).foundation('open');
        $(modal).css('top', '150px');

        $(document).on('closed.zf.reveal', '[data-reveal]', function (evt) {
          document.getElementById('filter_spinner').style.display = 'none';
          document.getElementById('filter_icon').style.display = 'inline-block';

          // Remove click event listener, to avoid a build-up and duplication of modal selection submissions.
          $(document).off('click', '#multiple_options_submit');
        });

        $(document).on('click', '#multiple_options_submit', function (evt) {
          window.handle_multiple_options_submit(modal);
        });
      }
    }

    window.handle_multiple_options_submit = (modal) => {

      // Re-submit query, with specified selections.
      const payload = {
        "prompt": document.getElementById('search').value,
        "post_type": mapbox_library_api.obj.settings.post_type,
        "selections": window.package_multiple_options_selections(),
        "filtered_fields": JSON.parse( decodeURIComponent( document.getElementById('multiple_options_filtered_fields').value ) ),
        "pii": JSON.parse( decodeURIComponent( document.getElementById('multiple_options_pii').value ) )
      };

      // Close modal and proceed with re-submission.
      $(modal).foundation('close');

      // Ensure spinner is still spinning.
      document.getElementById('filter_spinner').style.display = 'inline-block';
      document.getElementById('filter_icon').style.display = 'none';

      // Submit selections.
      jQuery.ajax({
        type: "POST",
        data: JSON.stringify(payload),
        contentType: "application/json; charset=utf-8",
        dataType: "json",
        url: `${mapbox_library_api.obj.settings.rest_endpoints_base}/create_filter`,
        beforeSend: function(xhr) {
          xhr.setRequestHeader('X-WP-Nonce', mapbox_library_api.obj.settings.nonce);
        },
      })
      .done(function (data) {
        console.log(data);

        // If successful, load points.
        if ((data?.status === 'success') && (data?.points)) {
          window.load_points(data.points);

        } else if (data?.status === 'error') {
          alert( (data?.message) ? data.message : `${window.lodash.escape(window.dt_mapbox_metrics.translations.default_error_msg)}` );

        }

        // Stop spinning....
        document.getElementById('filter_spinner').style.display = 'none';
        document.getElementById('filter_icon').style.display = 'inline-block';

      })
      .fail(function (err) {
        console.log('error')
        console.log(err)

        document.getElementById('filter_spinner').style.display = 'none';
        document.getElementById('filter_icon').style.display = 'inline-block';
      });
    }

    window.package_multiple_options_selections = () => {
      let selections = {};

      /**
       * Locations.
       */

      const locations = $('tbody.locations');
      if (locations) {
        selections['locations'] = [];
        $(locations).find('tr').each((idx, tr) => {
          const prompt = $(tr).find('input.prompt').val();
          const selected_opt_id = $(tr).find('select.options option:selected').val();
          const selected_opt_label = $(tr).find('select.options option:selected').text();

          selections['locations'].push({
            'prompt': prompt,
            'id': selected_opt_id,
            'label': selected_opt_label
          });
        });
      }

      /**
       * Users.
       */

      const users = $('tbody.users');
      if (users) {
        selections['users'] = [];
        $(users).find('tr').each((idx, tr) => {
          const prompt = $(tr).find('input.prompt').val();
          const selected_opt_id = $(tr).find('select.options option:selected').val();
          const selected_opt_label = $(tr).find('select.options option:selected').text();

          selections['users'].push({
            'prompt': prompt,
            'id': selected_opt_id,
            'label': selected_opt_label
          });
        });
      }

      /**
       * Posts.
       */

      const posts = $('tbody.posts');
      if (posts) {
        selections['posts'] = [];
        $(posts).find('tr').each((idx, tr) => {
          const prompt = $(tr).find('input.prompt').val();
          const selected_opt_id = $(tr).find('select.options option:selected').val();
          const selected_opt_label = $(tr).find('select.options option:selected').text();

          selections['posts'].push({
            'prompt': prompt,
            'id': selected_opt_id,
            'label': selected_opt_label
          });
        });
      }

      return selections;
    }

    window.load_points = (
      points,
      layer_key = 'pointsLayer',
      color = '#11b4da',
      size = 6,
    ) => {

      layer_key = 'dt-ai-maps-' + layer_key;

      let mapLayer = mapbox_library_api.map.getLayer(layer_key);
      if (typeof mapLayer !== 'undefined') {
        mapbox_library_api.map.removeLayer(layer_key);
      }

      let mapSource = mapbox_library_api.map.getSource(
        `${layer_key}_pointsSource`
      );

      if (typeof mapSource !== 'undefined') {
        mapbox_library_api.map.removeSource(`${layer_key}_pointsSource`);
      }

      mapbox_library_api.map.addSource(`${layer_key}_pointsSource`, {
        type: 'geojson',
        data: points
      });

      mapbox_library_api.map.addLayer({
        id: layer_key,
        type: 'circle',
        source: `${layer_key}_pointsSource`,
        paint: {
          'circle-color': color,
          'circle-radius': size,
          'circle-stroke-width': 0.5,
          'circle-stroke-color': '#fff',
        }
      });

      /**
       * Add Event Listeners
       */

      mapbox_library_api.map.on(
        'click',
        layer_key,
        window.handle_point_clicks
      );

      mapbox_library_api.map.on('mouseenter', layer_key, function () {
        mapbox_library_api.map.getCanvas().style.cursor = 'pointer';
      });

      mapbox_library_api.map.on('mouseleave', layer_key, function () {
        mapbox_library_api.map.getCanvas().style.cursor = '';
      });

      jQuery(document).on('click', '.close-details', function () {
        jQuery('#geocode-details').hide();
      });
    }

    window.handle_point_clicks = (e) => {
      e.preventDefault();

      // Find all features within a bounding box around a point.
      let point = e.point;
      let width = 10;
      let height = 20;
      let b1 = [point.x - width / 2, point.y - height / 2];
      let b2 = [point.x + width / 2, point.y + height / 2];

      let features = [];
      let rendered_features = mapbox_library_api.map.queryRenderedFeatures([
        b1,
        b2,
      ]);

      $.each(rendered_features, function (idx, feature) {
        if (feature.source && feature.source.startsWith('dt-ai-maps-')) {
          features.push(feature);
        }
      });

      // Close and empty any existing record links.
      let geocode_details = $('#geocode-details');
      let geocode_details_content = $('#geocode-details-content');
      $(geocode_details).fadeOut('fast', () => {
        $(geocode_details_content).empty();

        // Proceed with repopulating and displaying post-record links.
        if (features.length > 0) {
          let content_html = ``;
          $.each(features, function (idx, feature) {
            if (idx > 20) {
              return;
            }
            if (
              feature.properties &&
              feature.properties.post_type &&
              feature.properties.post_id &&
              feature.properties.name
            ) {
              // Ensure the correct post-type is adopted for system-based query layers.
              let post_type = feature.properties.post_type;
              switch (post_type) {
                case 'system-users': {
                  post_type = 'contacts';
                  break;
                }
              }

              content_html += `
              <div class="grid-x" id="list-${window.lodash.escape(idx)}">
                <div class="cell">
                    <a target="_blank" href="${window.lodash.escape(window.wpApiShare.site_url)}/${window.lodash.escape(post_type)}/${window.lodash.escape(feature.properties.post_id)}">${window.lodash.escape(feature.properties.name)}</a>
                </div>
              </div>`;
            }
          });
          $(geocode_details_content).html(content_html);

          // Remove any duplicate links.
          window.remove_geocode_details_content_duplicates();

          $(geocode_details_content).fadeIn('fast');
          $(geocode_details).fadeIn('fast');
        }
      });
    }

    window.remove_geocode_details_content_duplicates = () => {
      let content = $('#geocode-details-content');
      let links = [];
      $(content)
      .find('a')
      .each(function (idx, link) {
        if (window.lodash.includes(links, $(link).attr('href'))) {
          $(link).parent().remove();
        } else {
          links.push($(link).attr('href'));
        }
      });
    }

  })
})();
