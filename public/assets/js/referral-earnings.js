$(document).ready(function () {
    const table = $('#admin-referral-earnings-table').DataTable();

    // Add filter parameters to AJAX request
    $('#admin-referral-earnings-table').on('preXhr.dt', function (e, settings, data) {
        data.status_filter = $('#status-filter').val() || '';
    });

    // Reload table when filter or refresh button changes
    $('#status-filter').on('change', function () {
        table.ajax.reload(null, false);
    });

    $('#refresh').on('click', function () {
        table.ajax.reload(null, false);
    });
});
