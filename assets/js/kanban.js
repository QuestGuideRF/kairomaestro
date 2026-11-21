(function () {
    'use strict';
    var board = document.getElementById('leadKanban');
    if (!board) return;
    var csrf = document.querySelector('meta[name="csrf-token"]');
    var feedback = document.getElementById('kanbanFeedback');
    var dragged = null;
    var sourceZone = null;
    function setFeedback(message, error) {
        if (!feedback) return;
        feedback.textContent = message;
        feedback.className = 'kanban-feedback ' + (error ? 'is-error' : 'is-success');
    }
    function updateCounts() {
        board.querySelectorAll('.kanban-column').forEach(function (column) {
            var count = column.querySelectorAll('.kanban-card').length;
            var badge = column.querySelector('.kanban-count');
            if (badge) badge.textContent = count;
        });
    }
    function persistMove(card, zone) {
        if (!card || !zone) return;
        var origin = card.parentElement;
        var oldStatus = card.dataset.status;
        var newStatus = zone.dataset.status;
        var nextSibling = card.nextSibling;
        var select = card.querySelector('.kanban-status-control');
        if (oldStatus === newStatus) {
            if (select) select.value = oldStatus;
            return;
        }
        zone.appendChild(card);
        if (select) select.value = newStatus;
        updateCounts();
        fetch('/api/lead-status.php', {
            method: 'POST',
            credentials: 'same-origin',
            headers: {'Content-Type': 'application/json', 'X-CSRF-Token': csrf ? csrf.content : ''},
            body: JSON.stringify({lead_id: Number(card.dataset.leadId), status: newStatus})
        }).then(function (response) {
            return response.json().then(function (data) { return {ok: response.ok, data: data}; });
        }).then(function (result) {
            if (!result.ok || !result.data.success) throw new Error(result.data.error || 'Статус не сохранён');
            card.dataset.status = newStatus;
            setFeedback('Статус заявки #' + card.dataset.leadId + ' сохранён.', false);
        }).catch(function (error) {
            if (nextSibling) origin.insertBefore(card, nextSibling); else origin.appendChild(card);
            card.dataset.status = oldStatus;
            if (select) select.value = oldStatus;
            updateCounts();
            setFeedback(error.message, true);
        });
    }
    board.addEventListener('dragstart', function (event) {
        if (event.target.closest('a, button, input, select, textarea')) {
            event.preventDefault();
            return;
        }
        dragged = event.target.closest('.kanban-card');
        if (!dragged) return;
        sourceZone = dragged.parentElement;
        dragged.classList.add('is-dragging');
        event.dataTransfer.effectAllowed = 'move';
        event.dataTransfer.setData('text/plain', dragged.dataset.leadId);
    });
    board.addEventListener('change', function (event) {
        var select = event.target.closest('.kanban-status-control');
        if (!select) return;
        var card = select.closest('.kanban-card');
        var zone = board.querySelector('.kanban-dropzone[data-status="' + CSS.escape(select.value) + '"]');
        persistMove(card, zone);
    });
    board.addEventListener('dragend', function () {
        if (dragged) dragged.classList.remove('is-dragging');
        board.querySelectorAll('.kanban-dropzone').forEach(function (zone) { zone.classList.remove('is-over'); });
        dragged = null;
        sourceZone = null;
    });
    board.querySelectorAll('.kanban-dropzone').forEach(function (zone) {
        zone.addEventListener('dragover', function (event) {
            event.preventDefault();
            zone.classList.add('is-over');
        });
        zone.addEventListener('dragleave', function () { zone.classList.remove('is-over'); });
        zone.addEventListener('drop', function (event) {
            event.preventDefault();
            zone.classList.remove('is-over');
            if (!dragged || zone === sourceZone) return;
            persistMove(dragged, zone);
        });
    });
}());
