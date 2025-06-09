function create_filter() {
  const text = document.getElementById('search').value;

  // Ensure a valid text prompt has been specified.
  if (text) {

    // Display processing spinner.
    let temp_spinner = document.getElementById('temp-spinner');
    temp_spinner.setAttribute('class', 'loading-spinner active');


    // Specify endpoint, payload and dispatch filter creation request.
    const url = jsObject.root + jsObject.parts.root + '/v1/' + jsObject.parts.type + '/create_filter';
    const payload = {
      action: 'get',
      parts: jsObject.parts,
      sys_type: jsObject.sys_type,
      filter: {
        prompt: text,
        post_type: jsObject.default_post_type
      }
    }

    fetch(url, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'X-WP-Nonce': jsObject.nonce // Include the nonce in the headers
      },
      body: JSON.stringify(payload)
    })
    .then(response => response.json())
    .then(json => {
      console.log(json);

      /**
       * Pause the flow accordingly, if multiple connection options are available.
       * If so, then display modal with connection options.
       */

      if (json?.status === 'error') {
        alert( json?.message );

        temp_spinner.setAttribute('class', 'loading-spinner inactive');

      } else if ((json?.status === 'multiple_options_detected') && (json?.multiple_options)) {
        show_multiple_options_modal(json.multiple_options, json?.pii, json?.fields);

      } else if ((json?.status === 'success') && (json?.posts)) {

        // Stop spinning....
        temp_spinner.setAttribute('class', 'loading-spinner inactive');

        load_list_items(json.posts);
      }

    })
    .catch(error => {
      console.error('Error:', error);

      // Hide processing spinner.
      temp_spinner.setAttribute('class', 'loading-spinner inactive');

    });
  }
}

function show_filter_clear_option() {
  const text = document.getElementById('search').value;
  const clear_button = document.getElementById('clear-button');

  if (!text && clear_button.style.display === 'block') {
    clear_button.setAttribute('style', 'display: none;');

  } else if (text && clear_button.style.display === 'none') {
    clear_button.setAttribute('style', 'display: block;');
  }
}

function clear_filter() {
  document.getElementById('search').value = '';
}

function show_multiple_options_modal(multiple_options, pii, filter_fields) {

  const modal = $('#modal-small');
  if (modal) {

    $(modal).find('#modal-small-title').html(`${window.lodash.escape(window.dt_ai_obj.translations.multiple_options.title)}`);

    /**
     * Location Options.
     */

    let locations_html = '';
    if (multiple_options?.locations && multiple_options.locations.length > 0) {

      locations_html += `
        <h4>${window.lodash.escape(window.dt_ai_obj.translations.multiple_options.locations)}</h4>
        <table class="widefat striped">
            <tbody class="ai-locations">
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

          locations_html += `<option value="ignore">${window.lodash.escape(window.dt_ai_obj.translations.multiple_options.ignore_option)}</option>`;

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
          <h4>${window.lodash.escape(window.dt_ai_obj.translations.multiple_options.users)}</h4>
          <table class="widefat striped">
              <tbody class="ai-users">
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

          users_html += `<option value="ignore">${window.lodash.escape(window.dt_ai_obj.translations.multiple_options.ignore_option)}</option>`;

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
          <h4>${window.lodash.escape(window.dt_ai_obj.translations.multiple_options.posts)}</h4>
          <table class="widefat striped">
              <tbody class="ai-posts">
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

          posts_html += `<option value="ignore">${window.lodash.escape(window.dt_ai_obj.translations.multiple_options.ignore_option)}</option>`;

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
            <span aria-hidden="true">${window.lodash.escape(window.dt_ai_obj.translations.multiple_options.submit_but)}</span>
        </button>
        <button class="button" data-close aria-label="submit" type="button">
            <span aria-hidden="true">${window.lodash.escape(window.dt_ai_obj.translations.multiple_options.close_but)}</span>
        </button>
        <input id="multiple_options_filtered_fields" type="hidden" value="${encodeURIComponent( JSON.stringify(filter_fields) )}" />
        <input id="multiple_options_pii" type="hidden" value="${encodeURIComponent( JSON.stringify(pii) )}" />
    `;

    $(modal).find('#modal-small-content').html(html);

    $(modal).foundation('open');
    $(modal).css('top', '150px');

    $(document).on('closed.zf.reveal', '[data-reveal]', function (evt) {
      let temp_spinner = document.getElementById('temp-spinner');
      temp_spinner.setAttribute('class', 'loading-spinner inactive');

      // Remove click event listener, to avoid a build-up and duplication of modal selection submissions.
      $(document).off('click', '#multiple_options_submit');
    });

    $(document).on('click', '#multiple_options_submit', function (evt) {
      window.handle_multiple_options_submit(modal);
    });
  }
}

function handle_multiple_options_submit(modal) {

  // Re-submit query, with specified selections.
  const url = jsObject.root + jsObject.parts.root + '/v1/' + jsObject.parts.type + '/create_filter';
  const payload = {
    action: 'get',
    parts: jsObject.parts,
    sys_type: jsObject.sys_type,
    filter: {
      prompt: document.getElementById('search').value,
      post_type: jsObject.default_post_type,
      selections: window.package_multiple_options_selections(),
      filtered_fields: JSON.parse( decodeURIComponent( document.getElementById('multiple_options_filtered_fields').value ) ),
      pii: JSON.parse( decodeURIComponent( document.getElementById('multiple_options_pii').value ) )
    }
  }

  // Close modal and proceed with re-submission.
  $(modal).foundation('close');

  // Ensure spinner is still spinning.
  let temp_spinner = document.getElementById('temp-spinner');
  temp_spinner.setAttribute('class', 'loading-spinner active');

  // Submit selections.
  jQuery.ajax({
    type: "POST",
    data: JSON.stringify(payload),
    contentType: "application/json; charset=utf-8",
    dataType: "json",
    url: url,
    beforeSend: function(xhr) {
      xhr.setRequestHeader('X-WP-Nonce', jsObject.nonce);
    }
  })
  .done(function (data) {
    console.log(data);

    // Stop spinning....
    temp_spinner.setAttribute('class', 'loading-spinner inactive');

    // If successful, list posts.
    if ((data?.status === 'success') && (data?.posts)) {
      load_list_items(data.posts);

    } else if (data?.status === 'error') {
      alert( data?.message );

    }
  })
  .fail(function (err) {
    console.log('error')
    console.log(err)

    temp_spinner.setAttribute('class', 'loading-spinner inactive');
  });
}

function package_multiple_options_selections() {
  let selections = {};

  /**
   * Locations.
   */

  const locations = $('tbody.ai-locations');
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

  const users = $('tbody.ai-users');
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

  const posts = $('tbody.ai-posts');
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

function load_list_items(posts) {

  // Clear down existing items list.
  const item_list = document.getElementById('list-items');
  const item_template = document.getElementById('list-item-template').content;
  item_list.replaceChildren([]);

  // Render new filtered posts.
  for (const item of posts) {
    const item_el = item_template.cloneNode(true);
    item_el.querySelector('li').id = `item-${item.ID}`;
    populate_list_item_template(item_el, item);
    item_list.append(item_el);
  }

}

function populate_list_item_template(item_el, item) {
  const link = item_el.querySelector('a');
  link.href = `javascript:load_post_details(${item.ID}, "${item.post_type}", "${item.name}")`;

  item_el.querySelector('.post-id').innerText = `(#${item.ID})`;
  item_el.querySelector('.post-title').innerText = item.name;
  item_el.querySelector('.post-updated-date').innerText = window.SHAREDFUNCTIONS.formatDate(item.last_modified?.timestamp);
}

function load_post_details(id, post_type, name) {

  const detail_title = document.getElementById('detail-title');
  const detail_post_id = document.getElementById('detail-title-post-id');
  const detail_template = document.getElementById('post-detail-template').content;
  const detail_container = document.getElementById('detail-content');

  // Set active class in the list
  document.querySelectorAll('#list .items li.active').forEach((el) => {
    el.classList.remove('active');
  });
  const list_item = document.getElementById(`item-${id}`);
  if (list_item) {
    list_item.classList.add('active');
  }

  // Set detail title
  detail_title.innerText = name;
  detail_post_id.innerText = `(#${id})`;

  // open detail panel
  document.getElementById('list').classList.remove('is-expanded');

  // Ajax call to fetch selected post record object.
  const url = jsObject.root + jsObject.parts.root + '/v1/' + jsObject.parts.type + '/get_post';
  const payload = {
    action: 'get',
    parts: jsObject.parts,
    post_id: id,
    post_type,
    comment_count: 2,
    sys_type: jsObject.sys_type
  }

  fetch(url, {
    method: 'POST',
    headers: {
      'Content-Type': 'application/json',
      'X-WP-Nonce': jsObject.nonce // Include the nonce in the headers
    },
    body: JSON.stringify(payload)
  })
  .then(response => response.json())
  .then(json => {
    console.log(json);

    // clone detail template
    const content = detail_template.cloneNode(true);
    content.getElementById('post-id').value = id;
    content.getElementById('post-type').value = post_type;

    /**
     * Assuming a valid post object has been returned; attempt to refresh and populate accordingly.
     */

    if ( json?.success && json?.post ) {

      // set value of all inputs in the template
      set_input_values(content, json.post);

      const button = content.getElementById('comment-button');
      button.addEventListener('click', () => {
        submit_comment(id, post_type);
      });
      const comment_tile = content.getElementById('comments-tile');
      set_comments(comment_tile, json?.comments);

    }

    // insert templated content into detail panel
    detail_container.replaceChildren(content);

  })
  .catch(error => {
    console.error('Error:', error);
  });
}

function toggle_panels() {
  document.querySelectorAll('#list').forEach((el) => {
    el.classList.toggle('is-expanded');
  });

  // clear active classes on list
  document.querySelectorAll('#list .items li.active').forEach((el) => {
    el.classList.remove('active');
  })
}

/**
 * Set the values of all dt-* components within a container
 * @param {Element} parent
 * @param {Object} post
 */
function set_input_values(parent, post) {
  const elements = parent.childNodes;

  for (const element of elements) {
    if (!element.tagName) {
      continue;
    }
    const tagName = element.tagName.toLowerCase();
    const name = element.attributes.name ? element.attributes.name.value : null;

    const postValue = post[name];

    switch (tagName) {
      case 'dt-date':
        if (postValue && postValue.timestamp) {
          const date = new Date(postValue.timestamp * 1000);
          element.value = date.toISOString().substring(0, 10);
        }
        break;
      case 'dt-single-select':
        element.value = postValue?.key;
        break;
      case 'dt-tile':
        set_input_values(element, post);
        break;
      default:
        if (tagName.startsWith('dt-')) {
          element.value = post[name];
        }
    }
  }
}

function set_comments(comment_tile, comments) {

  // First, clear down all stale comments.
  const stale_comments = comment_tile.querySelectorAll('.activity-block, .action-block');
  if (stale_comments.length) {
    for (const comment of stale_comments) {
      comment.parentNode.removeChild(comment);
    }
  }

  // Assuming we have comments, display accordingly.
  if (comments && comments?.comments) {
    for (const val of comments.comments) {
      const actionBlock = document.createElement('div');
      actionBlock.className = "action-block";

      const activityBlock = document.createElement("div");
      activityBlock.className = "activity-block";

      const commentHeaderTemplate = document.getElementById('comment-header-template').content;
      const commentHeader = commentHeaderTemplate.cloneNode(true);
      const commentAuthor = commentHeader.getElementById('comment-author');
      const commentDate = commentHeader.getElementById('comment-date');

      commentAuthor.innerText = val['comment_author'];
      const commentDateTime = window.moment(val.comment_date_gmt + 'Z');
      commentDate.innerText = window.SHAREDFUNCTIONS.formatDate(
        moment(commentDateTime).unix(),
        true,
      );

      const commentContentTemplate = document.getElementById('comment-content-template').content;
      const commentContent = commentContentTemplate.cloneNode(true);
      const commentId = commentContent.getElementById('comment-id');
      const commentText = commentContent.getElementById('comment-content');

      commentId.className = "comment-bubble " + val['comment_ID'];
      commentId.setAttribute("data-comment-id", val['comment_ID']);
      commentText.setAttribute("title", val['comment_date']);
      commentText.innerText = val['comment_content'];

      activityBlock.appendChild(commentHeader);
      activityBlock.appendChild(commentContent);

      comment_tile.appendChild(actionBlock);
      comment_tile.appendChild(activityBlock);
    }
  }

}

function submit_comment(post_id, post_type) {
  const textArea = document.getElementById('comments-text-area');
  if (!textArea.value) {
    return false;
  }

  const payload = {
    action: 'post',
    parts: jsObject.parts,
    sys_type: jsObject.sys_type,
    post_id,
    post_type,
    comment: textArea.value,
    comment_count: 2
  }

  const url = jsObject.root + jsObject.parts.root + '/v1/' + jsObject.parts.type + '/comment';

  fetch(url, {
    method: "POST",
    headers: {
      "Content-Type": "application/json; charset=utf-8",
      "X-WP-Nonce": jsObject.nonce,
    },
    body: JSON.stringify(payload),
  })
  .then(response => response.json())
  .then((json) => {
    textArea.value = '';

    if (json?.success) {
      set_comments(document.getElementById('comments-tile'), json?.comments);
    }
  })
  .catch((reason) => {
    console.log("reason:");
    console.log(reason);
  });
}

/**
 * Submit event for saving detail form
 * @param {Event} event
 */
function save_item(event) {
  event.preventDefault();

  const form = event.target.closest('form');
  const formdata = new FormData(form);

  const data = {
    form: {},
    el: {},
  };
  formdata.forEach((value, key) => (data.form[key] = value));
  Array.from(form.elements).forEach((el) => {
    if (el.localName.startsWith('dt-')) {
      data.el[el.name] = el.value;
    }
  });

  const id = formdata.get('id');
  submit_comment(id, jsObject.default_post_type);
  let payload = {
    action: 'get',
    parts: jsObject.parts,
    sys_type: jsObject.sys_type,
    post_id: id,
    post_type: jsObject.default_post_type,
    fields: {
      dt: [],
      custom: [],
    }
  }

  Array.from(form.elements).forEach((el) => {
    if (!el.localName.startsWith('dt-')) {
      return;
    }
    // if readonly: skip
    if (el.disabled) {
      return;
    }
    const field_id = el.name;
    const type = el.dataset.type;

    // const value = DtWebComponents.ComponentService.convertValue(el.localName, el.value);
    const value = window.WebComponentServices.ComponentService.convertValue(el.localName, el.value);
    const fieldType = type === 'custom' ? 'custom' : 'dt';
    payload['fields'][fieldType].push({
      id: field_id,
      type,
      value: value,
    });
  });

  const url = jsObject.root + jsObject.parts.root + '/v1/' + jsObject.parts.type + '/update';
  fetch(url, {
    method: "POST", // *GET, POST, PUT, DELETE, etc.
    headers: {
      "Content-Type": "application/json; charset=utf-8",
      "X-WP-Nonce": jsObject.nonce,
    },
    body: JSON.stringify(payload), // body data type must match "Content-Type" header
  })
  .then((response) => response.json())
  .then((json) => {
    console.log(json);

    if (json.success && json.post) {
      show_notification(jsObject.translations['item_saved'], 'success');

      // update list item
      const itemEl = document.getElementById(`item-${json.post.ID}`);
      populate_list_item_template(itemEl, json.post);

      // go back to list
      toggle_panels();
    }
  })
  .catch((reason) => {
    console.log(reason);
  });
}

/**
 * Insert new notification message into snackbar area
 * @param message - Content of message
 * @param type - CSS class to add (e.g. success, error)
 * @param duration - Duration (ms) to keep message visible
 */
function show_notification(message, type, duration = 5000) {
  const template = document.getElementById('snackbar-item-template').content;
  const newItem = template.cloneNode(true);
  const now = Date.now()
  const itemEl = newItem.querySelector('.snackbar-item');

  if (type) {
    itemEl.classList.add(type);
  }
  itemEl.innerText = message;
  const elId = `snack-${now}`
  itemEl.id = elId;
  document.getElementById('snackbar-area').appendChild(newItem);

  setTimeout(async () => {
    const el = document.getElementById(elId);

    // wait for CSS transition
    el.classList.add('exiting');
    await new Promise(r => setTimeout(r, 500));

    // remove element from DOM
    el.remove();
  }, duration);
}
