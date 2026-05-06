$(document).ready(function () {
    const table = $('#admin-db-referrals-referred-table').DataTable();

    // Add filter parameters to AJAX request
    $('#admin-db-referrals-referred-table').on('preXhr.dt', function (e, settings, data) {
        data.status = $('#statusFilter').val() || '';
    });

    // Reload table when filter or refresh button changes
    $('#statusFilter').on('change', function () {
        table.ajax.reload(null, false);
    });

    $('#refresh').on('click', function () {
        table.ajax.reload(null, false);
    });
});
