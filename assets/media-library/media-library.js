/**
 * sw-gallery: confirmation before an image that galleries use is deleted permanently (Media Library list view and
 * attachment edit screen). Plain classic JavaScript, no dependencies, no network access.
 *
 * The server prints `data-swg-used="<number of galleries>"` on the "Galleries" column cell and on the attachment
 * details field, and the messages as `window.swgMediaLibrary` (translated). This script only asks and, when the
 * user declines, stops the deletion; it never deletes anything itself.
 */
(function () {
  'use strict';

  var messages = window.swgMediaLibrary || {};

  function usedCount(element) {
    var holder = element && element.querySelector ? element.querySelector('[data-swg-used]') : null;
    var value = holder ? parseInt(holder.getAttribute('data-swg-used'), 10) : 0;
    return value > 0 ? value : 0;
  }

  function format(count, one, many) {
    var template = count === 1 ? one : many;
    return typeof template === 'string' ? template.replace('%d', String(count)) : '';
  }

  function isPermanentDelete(link) {
    var href = link.getAttribute('href') || '';
    return /[?&]action=delete(&|$)/.test(href);
  }

  /** Row action "Delete Permanently" (list view) and the delete link of the attachment edit screen. */
  function onClick(event) {
    var target = event.target;
    var link = target && target.closest ? target.closest('a.submitdelete') : null;
    if (!link || !isPermanentDelete(link)) {
      return;
    }
    var row = link.closest('tr');
    // On the edit screen there is no table row around the link: the details field sits in the same form.
    var count = usedCount(row || document);
    if (count === 0) {
      return;
    }
    var message = format(count, messages.singleOne, messages.singleMany);
    if (message !== '' && !window.confirm(message)) {
      // Capture phase: runs before the inline confirm of core, so declining stops everything.
      event.preventDefault();
      event.stopImmediatePropagation();
    }
  }

  /** Bulk action "Delete permanently" of the list table. */
  function onSubmit(event) {
    var form = event.target;
    if (!form || form.id !== 'posts-filter') {
      return;
    }
    var top = form.querySelector('select[name="action"]');
    var bottom = form.querySelector('select[name="action2"]');
    var isDelete = (top && top.value === 'delete') || (bottom && bottom.value === 'delete');
    if (!isDelete) {
      return;
    }
    var used = 0;
    var boxes = form.querySelectorAll('tbody .check-column input[type="checkbox"]:checked');
    Array.prototype.forEach.call(boxes, function (box) {
      if (usedCount(box.closest('tr')) > 0) {
        used += 1;
      }
    });
    if (used === 0) {
      return;
    }
    var message = format(used, messages.bulkOne, messages.bulkMany);
    if (message !== '' && !window.confirm(message)) {
      event.preventDefault();
      event.stopImmediatePropagation();
    }
  }

  document.addEventListener('click', onClick, true);
  document.addEventListener('submit', onSubmit, true);
})();
