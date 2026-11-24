
<?php
require_once 'config.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Admin access only
if (
    empty($_SESSION['user_id']) ||
    ($_SESSION['role'] ?? '') !== 'admin'
) {
    header('Location: index.php');
    exit;
}

$pageTitle = 'Residents';
$activePage = 'residents';

$limit = 5;
$search = trim($_GET['search'] ?? '');
$page = max(1, (int)($_GET['page'] ?? 1));

// =====================================
// GET TOTAL RESIDENTS
// =====================================

$searchValue = "%{$search}%";

$countSql = "
    SELECT COUNT(*) AS total
    FROM users
    WHERE role = 'resident'
    AND (
        full_name LIKE ?
        OR username LIKE ?
    )
";

$stmt = $conn->prepare($countSql);
$stmt->bind_param("ss", $searchValue, $searchValue);
$stmt->execute();

$totalResidents = (int)$stmt->get_result()->fetch_assoc()['total'];
$stmt->close();

// =====================================
// PAGINATION
// =====================================

$totalPages = max(1, (int)ceil($totalResidents / $limit));
$page = min($page, $totalPages);
$offset = ($page - 1) * $limit;

$showingFrom = $totalResidents > 0 ? $offset + 1 : 0;
$showingTo = min($offset + $limit, $totalResidents);

// =====================================
// GET RESIDENT RECORDS
// =====================================

$sql = "
    SELECT full_name, username, created_at
    FROM users
    WHERE role = 'resident'
    AND (
        full_name LIKE ?
        OR username LIKE ?
    )
    ORDER BY id DESC
    LIMIT ? OFFSET ?
";

$stmt = $conn->prepare($sql);
$stmt->bind_param(
    "ssii",
    $searchValue,
    $searchValue,
    $limit,
    $offset
);

$stmt->execute();
$residents = $stmt->get_result();

// =====================================
// RENDER TABLE ROWS
// =====================================

ob_start();

if ($residents->num_rows > 0):

    while ($resident = $residents->fetch_assoc()):
        $initial = strtoupper(substr($resident['full_name'], 0, 1));
?>

<tr>
    <td>
        <div class="resident-name">
            <div class="resident-avatar">
                <?= htmlspecialchars($initial) ?>
            </div>

            <div class="resident-details">
                <strong>
                    <?= htmlspecialchars($resident['full_name']) ?>
                </strong>
                <small>Registered Resident</small>
            </div>
        </div>
    </td>

    <td>
        <span class="resident-username">
            <?= htmlspecialchars($resident['username']) ?>
        </span>
    </td>

    <td>
        <span class="resident-date">
            <?= !empty($resident['created_at'])
                ? date('M d, Y', strtotime($resident['created_at']))
                : 'N/A' ?>
        </span>
    </td>

    <td>
        <span class="resident-status">
            <span class="status-dot"></span>
            Active
        </span>
    </td>
</tr>

<?php
    endwhile;

else:
?>

<tr>
    <td colspan="4">
        <div class="resident-empty">
            <div class="empty-icon">
                <i class="bi bi-people"></i>
            </div>

            <strong>No residents found</strong>

            <p>
                <?= $search !== ''
                    ? 'Try searching with another name or username.'
                    : 'There are currently no registered residents.' ?>
            </p>
        </div>
    </td>
</tr>

<?php
endif;

$rowsHtml = ob_get_clean();

// =====================================
// RENDER PAGINATION
// =====================================

ob_start();

if ($totalPages > 1):
?>

<nav class="resident-pagination">

    <button type="button"
        data-page="<?= max(1, $page - 1) ?>"
        <?= $page <= 1 ? 'disabled' : '' ?>>
        <i class="bi bi-chevron-left"></i>
        Previous
    </button>

    <?php
    $startPage = max(1, $page - 2);
    $endPage = min($totalPages, $page + 2);

    for ($i = $startPage; $i <= $endPage; $i++):
    ?>

        <button type="button"
            data-page="<?= $i ?>"
            class="<?= $page === $i ? 'active' : '' ?>">
            <?= $i ?>
        </button>

    <?php endfor; ?>

    <button type="button"
        data-page="<?= min($totalPages, $page + 1) ?>"
        <?= $page >= $totalPages ? 'disabled' : '' ?>>
        Next
        <i class="bi bi-chevron-right"></i>
    </button>

</nav>

<?php
endif;

$paginationHtml = ob_get_clean();

$stmt->close();

// =====================================
// AJAX RESPONSE
// =====================================

if (isset($_GET['ajax'])) {

    header('Content-Type: application/json; charset=utf-8');

    echo json_encode([
        'rows' => $rowsHtml,
        'pagination' => $paginationHtml,
        'showingFrom' => $showingFrom,
        'showingTo' => $showingTo,
        'total' => $totalResidents,
        'page' => $page
    ]);

    exit;
}

// =====================================
// LOAD HEADER
// =====================================

require_once 'includes/header.php';
?>

<style>

/* =====================================
   RESIDENTS PAGE
===================================== */

.residents-page {
    width: 100%;
    padding: 5px 0 25px;
    color: #263445;
}

/* =====================================
   PAGE HEADING
===================================== */

.residents-heading {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 27px;
}

.page-eyebrow {
    display: inline-block;
    font-size: 11px;
    font-weight: 700;
    letter-spacing: 1.5px;
    color: #64748b;
    margin-bottom: 7px;
}

.residents-heading h2 {
    font-size: 27px;
    font-weight: 700;
    color: #1e293b;
    margin: 0 0 6px;
    letter-spacing: -0.7px;
}

.residents-heading p {
    font-size: 13px;
    color: #8491a3;
    margin: 0;
}

/* =====================================
   WHITE RESIDENT CARD
===================================== */

.residents-card {
    background: #ffffff;
    border: 1px solid #e9edf3;
    border-radius: 10px;
    box-shadow: 0 3px 15px rgba(15, 23, 42, 0.035);
    overflow: hidden;
}

/* =====================================
   TOOLBAR
===================================== */

.residents-toolbar {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 20px;
    padding: 22px 24px;
    border-bottom: 1px solid #edf0f4;
}

.residents-toolbar-left h3 {
    font-size: 16px;
    font-weight: 700;
    color: #263445;
    margin: 0 0 6px;
}

.residents-toolbar-left span {
    font-size: 12px;
    color: #8a96a6;
}

.residents-toolbar-left span strong {
    color: #2563eb;
    font-weight: 700;
}

/* =====================================
   TABLE
===================================== */

.residents-table-wrapper {
    width: 100%;
    overflow-x: auto;
}

.residents-table {
    width: 100%;
    border-collapse: collapse;
    margin: 0;
    min-width: 650px;
}

.residents-table thead th {
    background: #f8fafc;
    color: #8491a3;
    font-size: 10px;
    font-weight: 700;
    letter-spacing: 0.8px;
    padding: 15px 24px;
    text-align: left;
    border-bottom: 1px solid #edf0f4;
    white-space: nowrap;
}

.residents-table tbody td {
    padding: 16px 24px;
    border-bottom: 1px solid #f0f2f6;
    color: #526174;
    font-size: 12px;
    vertical-align: middle;
}

.residents-table tbody tr {
    transition: background 0.2s ease;
}

.residents-table tbody tr:hover {
    background: #fafcff;
}

.residents-table tbody tr:last-child td {
    border-bottom: none;
}

/* =====================================
   RESIDENT NAME AND AVATAR
===================================== */

.resident-name {
    display: flex;
    align-items: center;
    gap: 12px;
}

.resident-avatar {
    width: 39px;
    height: 39px;
    min-width: 39px;
    display: flex;
    align-items: center;
    justify-content: center;
    background: #eff6ff;
    color: #2563eb;
    border: 1px solid #dbeafe;
    border-radius: 8px;
    font-size: 14px;
    font-weight: 700;
}

.resident-details {
    display: flex;
    flex-direction: column;
    gap: 4px;
}

.resident-details strong {
    color: #334155;
    font-size: 12px;
    font-weight: 700;
}

.resident-details small {
    color: #9aa5b4;
    font-size: 10px;
}

.resident-username {
    color: #64748b;
    font-weight: 500;
}

.resident-date {
    color: #64748b;
}

/* =====================================
   STATUS
===================================== */

.resident-status {
    display: inline-flex;
    align-items: center;
    gap: 7px;
    padding: 6px 11px;
    background: #ecfdf5;
    color: #15803d;
    border: 1px solid #d1fae5;
    border-radius: 5px;
    font-size: 10px;
    font-weight: 700;
}

.status-dot {
    width: 6px;
    height: 6px;
    background: #22c55e;
    border-radius: 50%;
}

/* =====================================
   EMPTY STATE
===================================== */

.resident-empty {
    padding: 55px 20px;
    text-align: center;
}

.empty-icon {
    width: 52px;
    height: 52px;
    display: flex;
    align-items: center;
    justify-content: center;
    margin: 0 auto 15px;
    border-radius: 10px;
    background: #f1f5f9;
    color: #94a3b8;
    font-size: 23px;
}

.resident-empty strong {
    display: block;
    color: #334155;
    font-size: 14px;
    margin-bottom: 7px;
}

.resident-empty p {
    color: #94a3b8;
    font-size: 12px;
    margin: 0;
}

/* =====================================
   TABLE FOOTER
===================================== */

.residents-footer {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 15px;
    padding: 17px 24px;
    background: #ffffff;
    border-top: 1px solid #edf0f4;
}

.residents-showing {
    color: #94a3b8;
    font-size: 11px;
}

/* =====================================
   PAGINATION
===================================== */

.resident-pagination {
    display: flex;
    align-items: center;
    gap: 5px;
}

.resident-pagination button {
    min-width: 33px;
    height: 33px;
    padding: 0 10px;
    border: 1px solid #e5eaf0;
    border-radius: 5px;
    background: #ffffff;
    color: #64748b;
    font-size: 11px;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.2s ease;
}

.resident-pagination button:hover:not(:disabled) {
    border-color: #2563eb;
    color: #2563eb;
    background: #eff6ff;
}

.resident-pagination button.active {
    background: #2563eb;
    color: #ffffff;
    border-color: #2563eb;
}

.resident-pagination button:disabled {
    opacity: 0.4;
    cursor: not-allowed;
}

/* =====================================
   RESPONSIVE DESIGN
===================================== */

@media (max-width: 768px) {

    .residents-heading h2 {
        font-size: 23px;
    }

    .residents-toolbar {
        align-items: flex-start;
        flex-direction: column;
        padding: 19px;
    }

    .resident-search {
        width: 100%;
    }

    .residents-footer {
        align-items: flex-start;
        flex-direction: column;
        padding: 16px 19px;
    }

    .resident-pagination {
        flex-wrap: wrap;
    }

}

@media (max-width: 480px) {

    .residents-heading {
        margin-bottom: 20px;
    }

    .residents-heading h2 {
        font-size: 21px;
    }

    .residents-toolbar {
        gap: 15px;
    }

    .resident-pagination button {
        min-width: 30px;
        height: 31px;
        padding: 0 8px;
    }

}

</style>

<div class="residents-page">

    <!-- PAGE HEADING -->

    <div class="residents-heading">
        <div>
           
        </div>
    </div>

    <!-- RESIDENT WHITE CARD -->

    <div class="residents-card">

        <!-- TOOLBAR -->

        <div class="residents-toolbar">

            <div class="residents-toolbar-left">

                <span>
                    <strong id="residentTotal">
                        <?= number_format($totalResidents) ?>
                    </strong>
                    registered residents
                </span>
            </div>

            <div class="resident-search">

                <span class="search-icon">
                    <i class="bi bi-search"></i>
                </span>

                <input
                    type="search"
                    id="residentSearch"
                    placeholder="Search name or username..."
                    autocomplete="off"
                    value="<?= htmlspecialchars($search) ?>"
                >

            </div>

        </div>

        <!-- RESIDENT TABLE -->

        <div class="residents-table-wrapper">

            <table class="residents-table">

                <thead>
                    <tr>
                        <th>RESIDENT NAME</th>
                        <th>USERNAME</th>
                        <th>DATE REGISTERED</th>
                        <th>STATUS</th>
                    </tr>
                </thead>

                <tbody id="residentsTableBody">
                    <?= $rowsHtml ?>
                </tbody>

            </table>

        </div>

        <!-- TABLE FOOTER -->

        <div class="residents-footer">

            <div class="residents-showing" id="residentsShowing">
                Showing <?= $showingFrom ?>–<?= $showingTo ?>
                of <?= number_format($totalResidents) ?> residents
            </div>

            <div id="residentPagination">
                <?= $paginationHtml ?>
            </div>

        </div>

    </div>

</div>

<script>
document.addEventListener('DOMContentLoaded', function () {

    const searchInput = document.getElementById('residentSearch');
    const tableBody = document.getElementById('residentsTableBody');
    const pagination = document.getElementById('residentPagination');
    const showing = document.getElementById('residentsShowing');
    const total = document.getElementById('residentTotal');

    let searchTimer;
    let currentPage = <?= $page ?>;
    let requestController = null;

    // =================================
    // LOAD RESIDENTS USING AJAX
    // =================================

    function loadResidents(page = 1) {

        if (requestController) {
            requestController.abort();
        }

        requestController = new AbortController();

        const params = new URLSearchParams({
            ajax: '1',
            search: searchInput.value.trim(),
            page: page
        });

        tableBody.style.opacity = '0.45';

        fetch('residents.php?' + params.toString(), {
            signal: requestController.signal
        })
        .then(response => {
            if (!response.ok) {
                throw new Error('Unable to load residents.');
            }

            return response.json();
        })
        .then(data => {

            tableBody.innerHTML = data.rows;
            pagination.innerHTML = data.pagination;

            total.textContent = Number(data.total).toLocaleString();

            showing.textContent =
                `Showing ${data.showingFrom}–${data.showingTo} of ${Number(data.total).toLocaleString()} residents`;

            currentPage = data.page;

            tableBody.style.opacity = '1';

            // Update browser URL without reloading
            const url = new URL(window.location.href);

            if (searchInput.value.trim()) {
                url.searchParams.set('search', searchInput.value.trim());
            } else {
                url.searchParams.delete('search');
            }

            if (currentPage > 1) {
                url.searchParams.set('page', currentPage);
            } else {
                url.searchParams.delete('page');
            }

            window.history.replaceState({}, '', url);

        })
        .catch(error => {

            if (error.name !== 'AbortError') {
                console.error(error);
                tableBody.style.opacity = '1';
            }

        });
    }

    // =================================
    // AUTOMATIC SEARCH WHILE TYPING
    // =================================

    searchInput.addEventListener('input', function () {

        clearTimeout(searchTimer);

        searchTimer = setTimeout(function () {
            loadResidents(1);
        }, 300);

    });

    // =================================
    // PAGINATION BUTTONS
    // =================================

    pagination.addEventListener('click', function (event) {

        const button = event.target.closest('button[data-page]');

        if (!button || button.disabled) {
            return;
        }

        loadResidents(parseInt(button.dataset.page, 10));

    });

});
</script>

<?php require_once 'includes/footer.php'; ?>
