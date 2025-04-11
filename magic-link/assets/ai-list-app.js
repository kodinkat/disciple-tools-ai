document.addEventListener('DOMContentLoaded', function() {
  document.getElementById('search').addEventListener('keyup', function(e) {
    e.preventDefault();

    if (event.keyCode === 13) { // Enter key pressed.
      create_filter();
    }
  });
});

function init_mentions_search() {
  const tribute = new Tribute({
    triggerKeys: ['@'],
    values: (text, callback) => {
        
      // Specify endpoint, payload and dispatch mentions search request.
      const url = jsObject.root + jsObject.parts.root + '/v1/' + jsObject.parts.type + '/mentions_search';
      const payload = {
        action: 'post',
        parts: jsObject.parts,
        sys_type: jsObject.sys_type,
        search: text,
        post_type: jsObject.default_post_type
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

        let data = json?.options ?? [];

        // Filter out any items with undefined, null or non-string names
        data = data.filter(item => typeof item.name === 'string');

        // Sort data array entries by object name.
        data.sort((a, b) => {
            const aName = a.name.toUpperCase();
            const bName = b.name.toUpperCase();

            if (aName < bName) {
                return -1;

            } else if (aName > bName) {
                return 1;

            } else {
                return 0;
            }
        });

        callback(data);            
      })
      .catch((err) => {
          console.error(err);
          callback([]); // Return empty array on error
      });
    },
    menuItemTemplate: function (item) {
        if (!item || !item.original) return '';
        return `<div class="user-item">
            ${item.original.avatar ? `<img src="${item.original.avatar}">` : ''}
            <span class="name">${item.original.name || ''}</span>
            </div>`;
    },
    selectTemplate: function (item) {
        if (!item || !item.original || !item.original.name || !item.original.id) return '';
        return `@[${item.original.name}](${item.original.id})`;
    },
    lookup: 'name', // This should match the property you want to search by
    fillAttr: 'name', // This should be the property to display in the text field
    noMatchTemplate: null,
    searchOpts: {
        pre: '<span>',
        post: '</span>',
        skip: false
    }
  });

  // Attached tribute to the filter prompt input.
  const filter_prompt = document.getElementById('search');
  tribute.attach(filter_prompt);
}

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

      // Hide processing spinner.....
      temp_spinner.setAttribute('class', 'loading-spinner inactive');

      //....and refresh items list.
      if (json?.posts) {
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
