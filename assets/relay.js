(function () {
  'use strict';

  function withPresetTime(value, time) {
    if (!/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}/.test(value || '') || !/^(?:[01]\d|2[0-3]):[0-5]\d$/.test(time || '')) return value;
    return value.slice(0, 10) + 'T' + time;
  }

  function datePlusDays(value, days) {
    if (!/^\d{4}-\d{2}-\d{2}$/.test(value || '')) return value;
    var parts = value.split('-').map(Number);
    var date = new Date(Date.UTC(parts[0], parts[1] - 1, parts[2]));
    if (Number.isNaN(date.getTime())) return value;
    date.setUTCDate(date.getUTCDate() + Math.max(0, Math.min(3650, Number(days) || 0)));
    return date.toISOString().slice(0, 10);
  }

  function applyQuickPreset(form, preset) {
    if (!form || !preset || typeof preset !== 'object') return false;
    var field = function (name) { return form.querySelector('[name="' + name + '"]'); };
    ['frequency', 'interval'].forEach(function (key) {
      var control = field(key);
      if (control && preset[key] !== undefined) control.value = String(preset[key]);
    });
    var frequency = field('frequency');
    if (frequency) frequency.dispatchEvent(new Event('change', {bubbles:true}));
    return true;
  }

  function focusRelayTarget(target) {
    if (!target || typeof target.scrollIntoView !== 'function') return false;
    target.scrollIntoView({block:'start', behavior:'auto'});
    if (typeof target.focus === 'function') target.focus({preventScroll:true});
    return true;
  }

  function post(panel, operation, values) {
    var body = new URLSearchParams(values);
    body.set('relay_operation', operation);
    body.set(panel.dataset.tokenName, panel.dataset.tokenValue);
    return fetch(panel.dataset.endpoint, {
      method: 'POST',
      credentials: 'same-origin',
      headers: {
        'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8',
        'X-Requested-With': 'XMLHttpRequest'
      },
      body: body.toString()
    }).then(function (response) {
      return response.json().then(function (data) {
        if (!response.ok || !data.ok) throw new Error(data.message || panel.dataset.requestFailed);
        return data;
      });
    });
  }

  function setMessage(panel, message, failed) {
    var target = panel.querySelector('[data-relay-message]');
    if (!target) return;
    target.textContent = message;
    target.classList.toggle('is-error', Boolean(failed));
  }

  function showToast(admin, message, undo) {
    var toast = admin.querySelector('.RelayToast');
    if (!toast) return;
    var messageNode = toast.querySelector('[data-relay-toast-message]');
    var undoButton = toast.querySelector('[data-relay-toast-undo]');
    window.clearTimeout(toast._relayTimer);
    if (messageNode) messageNode.textContent = message;
    if (undoButton) {
      undoButton.hidden = typeof undo !== 'function';
      undoButton.onclick = typeof undo === 'function' ? function () {
        undoButton.disabled = true;
        Promise.resolve(undo()).catch(function (error) {
          showToast(admin, error.message, null);
        });
      } : null;
    }
    toast.hidden = false;
    toast.classList.add('is-visible');
    toast._relayTimer = window.setTimeout(function () {
      toast.classList.remove('is-visible');
      window.setTimeout(function () { toast.hidden = true; }, 180);
    }, 6000);
  }

  function closePopover(popover) {
    if (!popover) return;
    if (typeof popover.hidePopover === 'function' && popover.matches(':popover-open')) {
      popover.hidePopover();
    } else {
      popover.hidden = true;
    }
  }

  function openPopover(admin, source) {
    var popover = admin.querySelector('.RelayPopover');
    if (!popover) return;
    popover._relaySource = source;
    popover.querySelector('[data-popover-title]').textContent = source.dataset.pageTitle;
    popover.querySelector('[data-popover-template-detail]').textContent = source.dataset.templateLabel || popover.dataset.unknownTemplate;
    popover.querySelectorAll('[data-popover-template-name-detail]').forEach(function (templateName) {
      templateName.textContent = source.dataset.templateName || '';
      templateName.hidden = !source.dataset.templateName;
    });
    popover.querySelector('[data-popover-action]').textContent = source.dataset.actionLabel;
    popover.querySelector('[data-popover-date]').textContent = source.dataset.datetimeLabel;
    popover.querySelector('[data-popover-date]').dateTime = source.dataset.datetimeLocal;
    popover.querySelector('[data-popover-job-id]').textContent = source.dataset.jobId;
    popover.querySelector('[data-popover-page-id]').textContent = source.dataset.pageId;
    popover.querySelector('[data-popover-requester]').textContent = source.dataset.requester;
    popover.querySelector('[data-popover-actor]').textContent = source.dataset.actor;
    popover.querySelector('[data-popover-note]').textContent = source.dataset.note || popover.dataset.emptyNote;
    popover.querySelector('[data-popover-note-row]').hidden = source.dataset.noteVisible !== '1';

    var status = popover.querySelector('[data-popover-status]');
    status.textContent = source.dataset.statusLabel;
    status.className = 'RelayBadge is-' + source.dataset.status;

    var pageLink = popover.querySelector('[data-popover-page]');
    pageLink.href = source.dataset.pageUrl || '#';
    pageLink.hidden = !source.dataset.pageUrl;

    var publicUrl = source.dataset.publicUrl || '';
    var publicLink = popover.querySelector('[data-popover-public-url]');
    publicLink.href = publicUrl || '#';
    publicLink.textContent = publicUrl;
    publicLink.hidden = !publicUrl;
    popover.querySelector('[data-popover-no-url]').hidden = Boolean(publicUrl);
    popover.querySelector('[data-popover-copy-url]').hidden = !publicUrl;
    var viewLink = popover.querySelector('[data-popover-view]');
    viewLink.href = publicUrl || '#';
    viewLink.hidden = !publicUrl;

    var canManage = source.dataset.canManage === '1';
    var manage = popover.querySelector('[data-popover-manage]');
    var cancel = popover.querySelector('[data-popover-cancel]');
    manage.hidden = !canManage;
    cancel.hidden = !canManage;
    popover.querySelector('[data-popover-datetime]').value = source.dataset.datetimeLocal;

    if (typeof popover.showPopover === 'function') {
      if (!popover.matches(':popover-open')) popover.showPopover();
    } else {
      popover.hidden = false;
    }
    window.setTimeout(function () { popover.querySelector('[data-popover-close]').focus(); }, 0);
  }

  function rescheduleFromCalendar(admin, source, localDateTime) {
    return post(admin, 'reschedule', {
      job_id: source.dataset.jobId,
      scheduled_at: localDateTime,
      timezone: admin.dataset.timezone,
      run_as_user_id: source.dataset.runAs,
      note: source.dataset.note || ''
    });
  }

  function formatLocalDateTimeLabel(localDateTime) {
    var parts = String(localDateTime).split('T');
    var dateParts = (parts[0] || '').split('-').map(Number);
    var time = parts[1] || '';
    if (dateParts.length !== 3 || dateParts.some(function (part) { return !part; })) {
      return localDateTime.replace('T', ' · ');
    }
    var date = new Date(dateParts[0], dateParts[1] - 1, dateParts[2], 12, 0, 0);
    var locale = document.documentElement.lang || undefined;
    var label = new Intl.DateTimeFormat(locale, {year: 'numeric', month: 'short', day: 'numeric'}).format(date);
    return label + (time ? ' · ' + time : '');
  }

  function monthDayState(count) {
    return {hasEvents: count > 0, hiddenCount: Math.max(0, count - 3)};
  }

  function activeNavigationScrollLeft(clientWidth, itemOffset, itemWidth) {
    return Math.max(0, itemOffset - Math.max(0, (clientWidth - itemWidth) / 2));
  }

  function sortScheduledChildren(admin, container) {
    if (!container) return;
    var jobs = Array.from(container.children).filter(function (child) { return child.matches('[data-relay-job]'); });
    jobs.sort(function (left, right) {
      if (admin.dataset.sortOrder === 'template') {
        var templateOrder = (left.dataset.templateLabel || left.dataset.templateName || '').localeCompare(
          right.dataset.templateLabel || right.dataset.templateName || '',
          document.documentElement.lang || undefined,
          {numeric: true, sensitivity: 'base'}
        );
        if (templateOrder !== 0) return templateOrder;
      }
      return (left.dataset.datetimeLocal || '').localeCompare(right.dataset.datetimeLocal || '');
    });
    jobs.forEach(function (job) { container.appendChild(job); });
  }

  function refreshMonthDay(admin, day) {
    if (!day || !day.classList.contains('RelayDay')) return;
    var events = day.querySelector('.RelayDay__events');
    sortScheduledChildren(admin, events);
    var count = events ? events.querySelectorAll(':scope > [data-relay-job]').length : 0;
    var state = monthDayState(count);
    day.classList.toggle('has-events', state.hasEvents);
    var open = day.querySelector('.RelayDay__open');
    if (open) open.hidden = !state.hasEvents;
    var more = day.querySelector('.RelayDay__more');
    var hiddenCount = state.hiddenCount;
    if (more) {
      more.hidden = hiddenCount === 0;
      var countNode = more.querySelector('[data-relay-more-count]');
      if (countNode) {
        var template = hiddenCount === 1 ? admin.dataset.moreActionOne : admin.dataset.moreActionMany;
        countNode.textContent = String(template || '').replace('{count}', String(hiddenCount));
      }
    }
  }

  function moveEventToTarget(admin, source, target, localDateTime) {
    var sourceDay = source.closest('.RelayDay');
    var oldLabel = source.dataset.datetimeLabel || '';
    var destination = target;
    if (target.classList.contains('RelayAgendaDay')) {
      destination = target.querySelector('.RelayAgendaDay__body') || target;
      var empty = destination.querySelector('.RelayAgendaEmpty');
      if (empty) empty.remove();
    } else if (target.classList.contains('RelayDay')) {
      destination = target.querySelector('.RelayDay__events') || target;
    }
    if (!target.classList.contains('RelayQuarterDay')) {
      destination.appendChild(source);
    }
    source.dataset.datetimeLocal = localDateTime;
    source.dataset.datetimeLabel = formatLocalDateTimeLabel(localDateTime);
    if (source.title && oldLabel) source.title = source.title.replace(oldLabel, source.dataset.datetimeLabel);
    if (source.getAttribute('aria-label') && oldLabel) {
      source.setAttribute('aria-label', source.getAttribute('aria-label').replace(oldLabel, source.dataset.datetimeLabel));
    }
    var time = source.querySelector('time');
    if (time) time.textContent = localDateTime.split('T')[1];
    sortScheduledChildren(admin, destination);
    refreshMonthDay(admin, sourceDay);
    refreshMonthDay(admin, target);
  }

  function announceDrag(admin, message) {
    var status = admin.querySelector('[data-relay-drag-status]');
    if (status) status.textContent = message || '';
  }

  function removeDragPreview(source) {
    if (!source || !source._relayDragPreview) return;
    source._relayDragPreview.remove();
    source._relayDragPreview = null;
  }

  function setDragPreview(source, dataTransfer) {
    if (!dataTransfer || typeof dataTransfer.setDragImage !== 'function') return;
    var preview = source.cloneNode(true);
    preview.classList.add('RelayDragPreview');
    preview.removeAttribute('id');
    preview.removeAttribute('draggable');
    document.body.appendChild(preview);
    source._relayDragPreview = preview;
    dataTransfer.setDragImage(preview, 24, 18);
  }

  function initCalendar(admin) {
    if (admin.dataset.relayCalendarReady === '1') return;
    admin.dataset.relayCalendarReady = '1';
    var popover = admin.querySelector('.RelayPopover');
    var dragged = null;
    var ignoreClickUntil = 0;

    admin.querySelectorAll('[data-relay-job]').forEach(function (source) {
      var dragHandle = source.querySelector('.RelayEvent__drag');
      var dragArmed = !dragHandle;
      if (dragHandle && source.dataset.relayDraggable === '1') {
        source.setAttribute('draggable', 'false');
        dragHandle.addEventListener('pointerdown', function () {
          dragArmed = true;
          source.setAttribute('draggable', 'true');
        });
        dragHandle.addEventListener('pointerup', function () {
          dragArmed = false;
          source.setAttribute('draggable', 'false');
        });
        dragHandle.addEventListener('pointercancel', function () {
          dragArmed = false;
          source.setAttribute('draggable', 'false');
        });
      }
      source.addEventListener('click', function (event) {
        if (Date.now() < ignoreClickUntil) return;
        if (event.target.closest('a')) return;
        if (event.target.closest('.RelayEvent__drag')) return;
        event.preventDefault();
        openPopover(admin, source);
      });
      if (source.getAttribute('role') === 'button' && source.tagName !== 'BUTTON') {
        source.addEventListener('keydown', function (event) {
          if (event.key !== 'Enter' && event.key !== ' ') return;
          event.preventDefault();
          openPopover(admin, source);
        });
      }
      source.addEventListener('dragstart', function (event) {
        if (admin.dataset.dragDrop !== '1' || source.dataset.canManage !== '1' || !dragArmed) {
          event.preventDefault();
          if (dragHandle) source.setAttribute('draggable', 'false');
          return;
        }
        dragged = source;
        source.classList.add('is-dragging');
        source.setAttribute('aria-grabbed', 'true');
        event.dataTransfer.effectAllowed = 'move';
        event.dataTransfer.setData('text/plain', source.dataset.jobId);
        setDragPreview(source, event.dataTransfer);
        admin.classList.add('is-dragging-event');
        admin.querySelectorAll('[data-relay-drop-date]').forEach(function (target) {
          target.classList.toggle('is-drop-eligible', !target.dataset.pageId || target.dataset.pageId === source.dataset.pageId);
        });
        announceDrag(admin, admin.dataset.dragStartLabel.replace('{title}', source.dataset.pageTitle));
      });
      source.addEventListener('dragend', function () {
        removeDragPreview(source);
        if (dragHandle) {
          dragArmed = false;
          source.setAttribute('draggable', 'false');
        }
        source.classList.remove('is-dragging');
        source.removeAttribute('aria-grabbed');
        admin.classList.remove('is-dragging-event');
        admin.querySelectorAll('.is-drop-target, .is-drop-eligible').forEach(function (target) {
          target.classList.remove('is-drop-target', 'is-drop-eligible');
        });
        ignoreClickUntil = Date.now() + 250;
        announceDrag(admin, admin.dataset.dragEndLabel);
        dragged = null;
      });
    });

    admin.querySelectorAll('[data-relay-drop-date]').forEach(function (target) {
      function acceptsDrop() {
        return dragged && (!target.dataset.pageId || target.dataset.pageId === dragged.dataset.pageId);
      }
      target.addEventListener('dragenter', function (event) {
        if (!acceptsDrop()) return;
        event.preventDefault();
        target.classList.add('is-drop-target');
      });
      target.addEventListener('dragover', function (event) {
        if (!acceptsDrop()) return;
        event.preventDefault();
        event.dataTransfer.dropEffect = 'move';
      });
      target.addEventListener('dragleave', function (event) {
        if (!target.contains(event.relatedTarget)) target.classList.remove('is-drop-target');
      });
      target.addEventListener('drop', function (event) {
        if (!acceptsDrop()) return;
        event.preventDefault();
        target.classList.remove('is-drop-target');
        var source = dragged;
        var oldDateTime = source.dataset.datetimeLocal;
        var time = oldDateTime.split('T')[1] || '09:00';
        var newDateTime = target.dataset.relayDropDate + 'T' + time;
        if (newDateTime === oldDateTime) return;
        source.classList.add('is-saving');
        rescheduleFromCalendar(admin, source, newDateTime).then(function (data) {
          source.classList.remove('is-saving');
          announceDrag(admin, data.message);
          if (target.classList.contains('RelayQuarterDay')) {
            showToast(admin, data.message, null);
            window.setTimeout(function () { window.location.reload(); }, 450);
            return;
          }
          moveEventToTarget(admin, source, target, newDateTime);
          showToast(admin, data.message, function () {
            return rescheduleFromCalendar(admin, source, oldDateTime).then(function () { window.location.reload(); });
          });
        }).catch(function (error) {
          source.classList.remove('is-saving');
          showToast(admin, error.message, null);
        });
      });
    });

    if (popover) {
      popover.querySelector('[data-popover-copy-url]').addEventListener('click', function () {
        var source = popover._relaySource;
        if (!source || !source.dataset.publicUrl) return;
        if (!navigator.clipboard || typeof navigator.clipboard.writeText !== 'function') {
          showToast(admin, popover.dataset.copyFailed, null);
          return;
        }
        navigator.clipboard.writeText(source.dataset.publicUrl).then(function () {
          showToast(admin, popover.dataset.copySuccess, null);
        }).catch(function () {
          showToast(admin, popover.dataset.copyFailed, null);
        });
      });
      popover.querySelector('[data-popover-close]').addEventListener('click', function () {
        var source = popover._relaySource;
        closePopover(popover);
        if (source) source.focus();
      });
      popover.querySelector('[data-popover-reschedule]').addEventListener('click', function () {
        var source = popover._relaySource;
        var input = popover.querySelector('[data-popover-datetime]');
        if (!source || !input.value) return;
        var button = popover.querySelector('[data-popover-reschedule]');
        button.disabled = true;
        rescheduleFromCalendar(admin, source, input.value).then(function (data) {
          closePopover(popover);
          showToast(admin, data.message, null);
          window.setTimeout(function () { window.location.reload(); }, 350);
        }).catch(function (error) {
          showToast(admin, error.message, null);
          button.disabled = false;
        });
      });
      popover.querySelector('[data-popover-cancel]').addEventListener('click', function () {
        var source = popover._relaySource;
        if (!source || !window.confirm(admin.dataset.confirmCancel)) return;
        var button = popover.querySelector('[data-popover-cancel]');
        button.disabled = true;
        post(admin, 'cancel', {job_id: source.dataset.jobId}).then(function (data) {
          closePopover(popover);
          showToast(admin, data.message, null);
          window.setTimeout(function () { window.location.reload(); }, 350);
        }).catch(function (error) {
          showToast(admin, error.message, null);
          button.disabled = false;
        });
      });
    }

    var toastClose = admin.querySelector('[data-relay-toast-close]');
    if (toastClose) toastClose.addEventListener('click', function () {
      var toast = admin.querySelector('.RelayToast');
      toast.classList.remove('is-visible');
      toast.hidden = true;
    });
  }

  function init(panel) {
    if (panel.dataset.relayReady === '1') return;
    panel.dataset.relayReady = '1';
    var save = panel.querySelector('[data-relay-save]');
    var action = panel.querySelector('[data-relay-action]');
    var untilField = panel.querySelector('.RelayComposer__until');
    var editJob = panel.querySelector('[data-edit-job]');
    var cancelEdit = panel.querySelector('[data-relay-edit-cancel]');
    var saveLabel = panel.querySelector('[data-relay-save-label]');
    var squadSuggest = panel.querySelector('[data-relay-squad-suggest]');
    var composer = panel.querySelector('.RelayComposer');
    var startLabel = panel.querySelector('[data-relay-start-label]');
    var actionGuide = panel.querySelector('.RelayComposer__actionGuide');
    var startInput = panel.querySelector('[data-scheduled-at]');
    var presetTarget = startInput;
    var ruleSelect = panel.querySelector('[data-relay-rule-select]');
    var defaultRuleValue = ruleSelect ? ruleSelect.value : '';

    function applySchedulingRule() {
      if (!ruleSelect || !ruleSelect.value) return;
      var selected = ruleSelect.options[ruleSelect.selectedIndex];
      var untilInput = panel.querySelector('[data-scheduled-until]');
      var noteInput = panel.querySelector('[data-relay-note]');
      if (action && selected.dataset.action) action.value = selected.dataset.action;
      if (startInput && selected.dataset.start) startInput.value = selected.dataset.start;
      if (untilInput && selected.dataset.until) untilInput.value = selected.dataset.until;
      if (noteInput) noteInput.value = selected.dataset.note || '';
      var name = panel.querySelector('[data-relay-rule-name]');
      var summary = panel.querySelector('[data-relay-rule-summary]');
      if (name) name.textContent = selected.textContent.split(' · ')[0];
      if (summary) summary.textContent = selected.dataset.summary || '';
      presetTarget = startInput;
      syncActionFields();
      syncTimePresets();
    }

    function syncTimePresets() {
      var targetTime = presetTarget && presetTarget.value ? presetTarget.value.slice(11, 16) : '';
      panel.querySelectorAll('[data-relay-time-preset]').forEach(function (button) {
        button.setAttribute('aria-pressed', button.dataset.relayTimePreset === targetTime ? 'true' : 'false');
      });
    }

    if (composer) {
      composer.querySelectorAll('input[type="datetime-local"]').forEach(function (input) {
        input.addEventListener('focus', function () {
          presetTarget = input;
          syncTimePresets();
        });
        input.addEventListener('input', syncTimePresets);
        input.addEventListener('change', syncTimePresets);
      });
      composer.querySelectorAll('[data-relay-time-preset]').forEach(function (button) {
        button.addEventListener('click', function () {
          var target = presetTarget || startInput;
          if (!target) return;
          target.value = withPresetTime(target.value, button.dataset.relayTimePreset);
          target.dispatchEvent(new Event('input', {bubbles: true}));
          target.dispatchEvent(new Event('change', {bubbles: true}));
          target.focus();
        });
      });
      syncTimePresets();
    }

    if (ruleSelect) ruleSelect.addEventListener('change', applySchedulingRule);

    function syncActionFields() {
      if (!action || !untilField) return;
      var isWindow = action.value === 'window';
      untilField.hidden = !isWindow;
      var input = untilField.querySelector('input');
      if (input) input.required = isWindow;
      if (!isWindow && input && presetTarget === input) presetTarget = startInput;
      if (composer) composer.dataset.action = action.value;
      if (startLabel) {
        startLabel.textContent = action.value === 'unpublish'
          ? startLabel.dataset.labelUnpublish
          : startLabel.dataset.labelPublish;
      }
      if (actionGuide) {
        actionGuide.dataset.action = action.value;
        actionGuide.querySelectorAll('[data-relay-action-guide]').forEach(function (guide) {
          guide.hidden = guide.dataset.relayActionGuide !== action.value;
        });
        var guideIcon = actionGuide.querySelector('.fa');
        if (guideIcon) {
          guideIcon.className = 'fa ' + (action.value === 'unpublish' ? 'fa-arrow-down' : (action.value === 'window' ? 'fa-arrows-v' : 'fa-arrow-up'));
        }
      }
      syncTimePresets();
    }

    function resetEditor() {
      if (!editJob || !action || !save) return;
      editJob.value = '';
      action.disabled = false;
      if (ruleSelect) {
        ruleSelect.disabled = false;
        ruleSelect.value = defaultRuleValue;
      }
      if (defaultRuleValue) {
        applySchedulingRule();
      } else {
        action.value = 'publish';
        panel.querySelector('[data-relay-note]').value = '';
      }
      if (cancelEdit) cancelEdit.hidden = true;
      if (saveLabel) saveLabel.textContent = save.dataset.labelCreate;
      syncActionFields();
    }

    if (action) {
      action.addEventListener('change', syncActionFields);
      syncActionFields();
    }

    if (save) save.addEventListener('click', function () {
      save.disabled = true;
      setMessage(panel, panel.dataset.savingLabel, false);
      post(panel, editJob && editJob.value ? 'reschedule' : 'save', {
        job_id: editJob ? editJob.value : '',
        page_id: panel.querySelector('[data-page-id]').value,
        relay_action: panel.querySelector('[data-relay-action]').value,
        scheduled_at: panel.querySelector('[data-scheduled-at]').value,
        scheduled_until: panel.querySelector('[data-scheduled-until]').value,
        timezone: panel.querySelector('[data-timezone]').value,
        run_as_user_id: panel.querySelector('[data-run-as-user]').value,
        note: panel.querySelector('[data-relay-note]').value
      }).then(function (data) {
        setMessage(panel, data.message, false);
        window.setTimeout(function () { window.location.reload(); }, 350);
      }).catch(function (error) {
        setMessage(panel, error.message, true);
        save.disabled = false;
      });
    });

    if (squadSuggest) squadSuggest.addEventListener('click', function () {
      squadSuggest.disabled = true;
      setMessage(panel, panel.dataset.squadLoadingLabel, false);
      post(panel, 'squad-suggest', {
        page_id: panel.querySelector('[data-page-id]').value,
        relay_action: panel.querySelector('[data-relay-action]').value,
        scheduled_at: panel.querySelector('[data-scheduled-at]').value,
        scheduled_until: panel.querySelector('[data-scheduled-until]').value,
        timezone: panel.querySelector('[data-timezone]').value
      }).then(function (data) {
        var proposal = data.proposal || {};
        if (proposal.scheduled_at) panel.querySelector('[data-scheduled-at]').value = proposal.scheduled_at;
        if (proposal.scheduled_until) panel.querySelector('[data-scheduled-until]').value = proposal.scheduled_until;
        if (proposal.note) panel.querySelector('[data-relay-note]').value = proposal.note;
        var box = panel.querySelector('[data-relay-squad-proposal]');
        if (box) {
          box.hidden = false;
          box.querySelector('[data-relay-squad-rationale]').textContent = proposal.rationale || '';
        }
        setMessage(panel, data.message, false);
        syncTimePresets();
        squadSuggest.disabled = false;
      }).catch(function (error) {
        setMessage(panel, error.message, true);
        squadSuggest.disabled = false;
      });
    });

    panel.querySelectorAll('[data-relay-edit]').forEach(function (button) {
      button.addEventListener('click', function () {
        if (!editJob || !action || !save) return;
        editJob.value = button.dataset.relayEdit;
        action.value = button.dataset.action;
        action.disabled = true;
        if (ruleSelect) {
          ruleSelect.value = '';
          ruleSelect.disabled = true;
        }
        panel.querySelector('[data-scheduled-at]').value = button.dataset.time;
        panel.querySelector('[data-run-as-user]').value = button.dataset.runAs;
        panel.querySelector('[data-relay-note]').value = button.dataset.note;
        if (cancelEdit) cancelEdit.hidden = false;
        if (saveLabel) saveLabel.textContent = save.dataset.labelUpdate;
        syncActionFields();
        presetTarget = panel.querySelector('[data-scheduled-at]');
        syncTimePresets();
        panel.querySelector('.RelayComposer').scrollIntoView({behavior: 'smooth', block: 'center'});
      });
    });

    if (cancelEdit) cancelEdit.addEventListener('click', resetEditor);

    panel.querySelectorAll('[data-relay-cancel]').forEach(function (button) {
      button.addEventListener('click', function () {
        if (!window.confirm(panel.dataset.confirmCancel)) return;
        button.disabled = true;
        post(panel, 'cancel', {job_id: button.dataset.relayCancel})
          .then(function () { window.location.reload(); })
          .catch(function (error) {
            setMessage(panel, error.message, true);
            button.disabled = false;
          });
      });
    });
  }

  function revealActiveNavigation(nav) {
    var active = nav.querySelector('.uk-active a, [aria-current="page"]');
    if (!active) return;
    var navRect = nav.getBoundingClientRect();
    var activeRect = active.getBoundingClientRect();
    var activeOffset = activeRect.left - navRect.left + nav.scrollLeft;
    nav.scrollLeft = activeNavigationScrollLeft(nav.clientWidth, activeOffset, activeRect.width);
  }

  function initMobileViewJumps(admin) {
    admin.querySelectorAll('[data-relay-kanban-jump]').forEach(function (button) {
      button.addEventListener('click', function () {
        var board = admin.querySelector('.RelayKanban');
        var column = board && board.querySelector('[data-kanban-column="' + button.dataset.relayKanbanJump + '"]');
        if (!board || !column) return;
        var boardRect = board.getBoundingClientRect();
        var columnRect = column.getBoundingClientRect();
        board.scrollTo({left: Math.max(0, columnRect.left - boardRect.left + board.scrollLeft), behavior: 'smooth'});
        button.parentElement.querySelectorAll('button').forEach(function (candidate) {
          candidate.toggleAttribute('aria-current', candidate === button);
        });
      });
    });
    admin.querySelectorAll('[data-relay-timeline-jump]').forEach(function (button) {
      button.addEventListener('click', function () {
        var wrap = admin.querySelector('.RelayTimelineWrap');
        var day = wrap && wrap.querySelector('[data-timeline-date="' + button.dataset.relayTimelineJump + '"]');
        if (!wrap || !day) return;
        var stickyWidth = wrap.querySelector('.RelayTimeline__corner')?.offsetWidth || 0;
        var wrapRect = wrap.getBoundingClientRect();
        var dayRect = day.getBoundingClientRect();
        wrap.scrollTo({left: Math.max(0, dayRect.left - wrapRect.left + wrap.scrollLeft - stickyWidth), behavior: 'smooth'});
        button.parentElement.querySelectorAll('button').forEach(function (candidate) {
          candidate.toggleAttribute('aria-current', candidate === button);
        });
      });
    });
  }

  function boot() {
    document.querySelectorAll('.RelayPanel').forEach(init);
    document.querySelectorAll('.RelayAdmin').forEach(function (admin) {
      initCalendar(admin);
      initMobileViewJumps(admin);
    });
    document.querySelectorAll('.RelayAdminNav').forEach(function (nav) {
      window.requestAnimationFrame(function () { revealActiveNavigation(nav); });
    });
    document.querySelectorAll('[data-relay-reset-imitation], [data-relay-seed-imitation]').forEach(function (button) {
      if (button.dataset.relayReady === '1') return;
      button.dataset.relayReady = '1';
      button.addEventListener('click', function () {
        var host = button.closest('.RelayPanel, .RelayImitationHost');
        if (!host) return;
        var isSeed = button.hasAttribute('data-relay-seed-imitation');
        if (!isSeed && !window.confirm(host.dataset.confirmClearImitation)) return;
        button.disabled = true;
        post(host, isSeed ? 'seed-imitation' : 'reset-imitation', {})
          .then(function () { window.location.reload(); })
          .catch(function (error) {
            setMessage(host, error.message, true);
            button.disabled = false;
          });
      });
    });
    document.querySelectorAll('[data-relay-auto-submit]').forEach(function (select) {
      if (select.dataset.relayReady === '1') return;
      select.dataset.relayReady = '1';
      select.form.classList.add('is-enhanced');
      select.addEventListener('change', function () {
        var url = new URL(select.form.getAttribute('action') || window.location.pathname, window.location.href);
        url.search = '';
        new FormData(select.form).forEach(function (value, key) {
          if (String(value) !== '') url.searchParams.append(key, String(value));
        });
        window.location.assign(url.toString());
      });
    });
    document.querySelectorAll('[data-relay-month-picker]').forEach(function (input) {
      if (input.dataset.relayReady === '1') return;
      input.dataset.relayReady = '1';
      input.form.classList.add('is-enhanced');
      input.addEventListener('change', function () {
        if (input.value && input.checkValidity()) input.form.requestSubmit();
      });
    });
    document.querySelectorAll('[data-relay-copy-command]').forEach(function (button) {
      if (button.dataset.relayReady === '1') return;
      button.dataset.relayReady = '1';
      button.addEventListener('click', function () {
        var panel = button.closest('.RelayCronCommand');
        var source = panel && panel.querySelector('[data-relay-cron-command]');
        var label = button.querySelector('span');
        if (!source || !navigator.clipboard || typeof navigator.clipboard.writeText !== 'function') {
          if (label) label.textContent = panel ? panel.dataset.copyFailed : 'Copy failed';
          return;
        }
        var original = label ? label.textContent : '';
        navigator.clipboard.writeText(source.textContent).then(function () {
          if (label) label.textContent = panel.dataset.copySuccess;
          window.setTimeout(function () { if (label) label.textContent = original; }, 1800);
        }).catch(function () {
          if (label) label.textContent = panel.dataset.copyFailed;
          window.setTimeout(function () { if (label) label.textContent = original; }, 1800);
        });
      });
    });
    document.querySelectorAll('[data-relay-copy-calendar]').forEach(function (button) {
      if (button.dataset.relayReady === '1') return;
      button.dataset.relayReady = '1';
      button.addEventListener('click', function () {
        var panel = button.closest('.RelayCalendarFeedOnce');
        var source = panel && panel.querySelector('[data-relay-calendar-url]');
        var label = button.querySelector('span');
        var original = label ? label.textContent : '';
        if (!source || !navigator.clipboard || typeof navigator.clipboard.writeText !== 'function') {
          if (label) label.textContent = panel ? panel.dataset.copyFailed : 'Copy failed';
          return;
        }
        navigator.clipboard.writeText(source.textContent).then(function () {
          if (label) label.textContent = panel.dataset.copySuccess;
          window.setTimeout(function () { if (label) label.textContent = original; }, 1800);
        }).catch(function () {
          if (label) label.textContent = panel.dataset.copyFailed;
          window.setTimeout(function () { if (label) label.textContent = original; }, 1800);
        });
      });
    });
    document.querySelectorAll('[data-relay-scroll-target]').forEach(function (link) {
      if (link.dataset.relayReady === '1') return;
      link.dataset.relayReady = '1';
      link.addEventListener('click', function (event) {
        var target = document.getElementById(link.dataset.relayScrollTarget || '');
        if (!target) return;
        event.preventDefault();
        if (window.history && typeof window.history.replaceState === 'function') {
          window.history.replaceState(null, '', '#' + encodeURIComponent(target.id));
        }
        focusRelayTarget(target);
      });
    });
    document.querySelectorAll('[data-relay-import-file]').forEach(function (input) {
      if (input.dataset.relayReady === '1') return;
      input.dataset.relayReady = '1';
      input.addEventListener('change', function () {
        var wrapper = input.closest('.RelayTransferFile');
        var name = wrapper && wrapper.querySelector('[data-relay-file-name]');
        if (name) name.textContent = input.files && input.files[0] ? input.files[0].name : input.dataset.emptyLabel;
      });
    });
    document.querySelectorAll('[data-relay-rule-form]').forEach(function (form) {
      if (form.dataset.relayReady === '1') return;
      form.dataset.relayReady = '1';
      var frequency = form.querySelector('[data-relay-rule-frequency]');
      var interval = form.querySelector('[name="interval"]');
      var ends = form.querySelector('[data-relay-rule-ends]');
      var action = form.querySelector('[data-relay-rule-action]');
      function syncRuleForm() {
        var weekdays = form.querySelector('[data-relay-rule-weekdays]');
        var until = form.querySelector('[data-relay-rule-until]');
        var count = form.querySelector('[data-relay-rule-count]');
        var windowDuration = form.querySelector('[data-relay-rule-window]');
        if (weekdays) weekdays.hidden = !frequency || frequency.value !== 'week';
        if (interval) {
          interval.max = frequency && frequency.value === 'minute' ? '10080' : '99';
          if (Number(interval.value) > Number(interval.max)) interval.value = interval.max;
        }
        if (until) until.hidden = !ends || ends.value !== 'on';
        if (count) count.hidden = !ends || ends.value !== 'after';
        if (windowDuration) windowDuration.hidden = !action || action.value !== 'window';
      }
      form._relaySyncRuleForm = syncRuleForm;
      [frequency, ends, action].forEach(function (control) {
        if (control) control.addEventListener('change', syncRuleForm);
      });
      syncRuleForm();
    });
    document.querySelectorAll('[data-relay-rule-preset]').forEach(function (button) {
      if (button.dataset.relayReady === '1') return;
      button.dataset.relayReady = '1';
      button.addEventListener('click', function () {
        var form = document.querySelector('[data-relay-rule-form]');
        var preset;
        try { preset = JSON.parse(button.dataset.preset || '{}'); } catch (error) { return; }
        if (!applyQuickPreset(form, preset)) return;
        document.querySelectorAll('[data-relay-rule-preset]').forEach(function (candidate) {
          candidate.setAttribute('aria-pressed', candidate === button ? 'true' : 'false');
        });
        var editor = form.closest('.RelayRuleEditor');
        if (editor) editor.scrollIntoView({block:'start'});
        var first = form.querySelector('[name="rule_name"]');
        if (first) first.focus({preventScroll:true});
      });
    });
    document.querySelectorAll('[data-relay-rule-delete], [data-relay-preset-delete]').forEach(function (form) {
      form.addEventListener('submit', function (event) {
        if (!window.confirm(form.dataset.confirm)) event.preventDefault();
      });
    });
  }

  if (window.RelayTestHooks && typeof window.RelayTestHooks === 'object') {
    Object.assign(window.RelayTestHooks, {
      activeNavigationScrollLeft: activeNavigationScrollLeft,
      formatLocalDateTimeLabel: formatLocalDateTimeLabel,
      monthDayState: monthDayState,
      withPresetTime: withPresetTime,
      datePlusDays: datePlusDays,
      applyQuickPreset: applyQuickPreset,
      focusRelayTarget: focusRelayTarget
    });
  }

  document.addEventListener('DOMContentLoaded', boot);
  document.addEventListener('wiretabclick', boot);
  boot();
}());
