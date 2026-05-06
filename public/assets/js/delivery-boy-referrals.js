$(document).ready(function () {
    const table = $('#admin-db-referrals-table').DataTable();

    // Reload table when refresh button changes
    $('#refresh').on('click', function () {
        table.ajax.reload(null, false);
    });
});
