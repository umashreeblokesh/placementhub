<?php
session_start();
require_once __DIR__ . '/db.php';

mysqli_set_charset($conn, 'utf8mb4');

if (empty($_SESSION['placementhub_csrf'])) {
    $_SESSION['placementhub_csrf'] = bin2hex(random_bytes(32));
}

function h($value) {
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
}

function companyRedirect($message) {
    header('Location: companies.php?message=' . urlencode($message));
    exit;
}

$form = [
    'company_id' => '',
    'company_name' => '',
    'location' => '',
    'industry' => ''
];

$error = '';
$message = $_GET['message'] ?? '';
$editing = false;

// Handle company form submissions.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['csrf_token'] ?? '';

    if (!hash_equals($_SESSION['placementhub_csrf'], $token)) {
        http_response_code(403);
        exit('Invalid request token. Refresh the page and try again.');
    }

    $action = $_POST['action'] ?? '';

    if ($action === 'delete') {
        $id = filter_var($_POST['company_id'] ?? '', FILTER_VALIDATE_INT);

        if (!$id || $id < 1) {
            companyRedirect('invalid_id');
        }

        $stmt = mysqli_prepare(
            $conn,
            'DELETE FROM Company WHERE company_id = ?'
        );
        mysqli_stmt_bind_param($stmt, 'i', $id);
        $success = mysqli_stmt_execute($stmt);
        $errno = mysqli_stmt_errno($stmt);
        $affected = mysqli_stmt_affected_rows($stmt);
        mysqli_stmt_close($stmt);

        if ($success && $affected > 0) {
            companyRedirect('deleted');
        }

        if ($success) {
            companyRedirect('not_found');
        }

        if ($errno === 1451) {
            companyRedirect('delete_blocked');
        }

        error_log('PlacementHub company deletion failed. Error code: ' . $errno);
        companyRedirect('save_error');
    }

    if ($action === 'add' || $action === 'update') {
        $form['company_id'] = trim($_POST['company_id'] ?? '');
        $form['company_name'] = trim($_POST['company_name'] ?? '');
        $form['location'] = trim($_POST['location'] ?? '');
        $form['industry'] = trim($_POST['industry'] ?? '');

        $id = filter_var($form['company_id'], FILTER_VALIDATE_INT);

        if ($action === 'update' && (!$id || $id < 1)) {
            $error = 'Invalid company ID.';
        } elseif (
            $form['company_name'] === '' ||
            mb_strlen($form['company_name']) > 100
        ) {
            $error = 'Company name is required and must not exceed 100 characters.';
        } elseif (mb_strlen($form['location']) > 100) {
            $error = 'Location must not exceed 100 characters.';
        } elseif (mb_strlen($form['industry']) > 100) {
            $error = 'Industry must not exceed 100 characters.';
        }

        if ($error === '') {
            $name = $form['company_name'];
            $location = $form['location'] === '' ? null : $form['location'];
            $industry = $form['industry'] === '' ? null : $form['industry'];

            if ($action === 'add') {
                $stmt = mysqli_prepare(
                    $conn,
                    'INSERT INTO Company (company_name, location, industry)
                     VALUES (?, ?, ?)'
                );

                mysqli_stmt_bind_param(
                    $stmt,
                    'sss',
                    $name,
                    $location,
                    $industry
                );
            } else {
                $stmt = mysqli_prepare(
                    $conn,
                    'UPDATE Company
                     SET company_name = ?, location = ?, industry = ?
                     WHERE company_id = ?'
                );

                mysqli_stmt_bind_param(
                    $stmt,
                    'sssi',
                    $name,
                    $location,
                    $industry,
                    $id
                );
            }

            $success = mysqli_stmt_execute($stmt);
            $errno = mysqli_stmt_errno($stmt);
            mysqli_stmt_close($stmt);

            if ($success) {
                companyRedirect($action === 'add' ? 'added' : 'updated');
            }

            if ($errno === 1062) {
                $error = 'A company with that name already exists.';
            } else {
                error_log('PlacementHub company save failed. Error code: ' . $errno);
                $error = 'Could not save the company. Please try again.';
            }
        }

        $editing = ($action === 'update');
    }
}

// Load company details for editing.
if ($_SERVER['REQUEST_METHOD'] !== 'POST' && isset($_GET['edit'])) {
    $editId = filter_var($_GET['edit'], FILTER_VALIDATE_INT);

    if ($editId && $editId > 0) {
        $stmt = mysqli_prepare(
            $conn,
            'SELECT company_id, company_name, location, industry
             FROM Company WHERE company_id = ?'
        );

        mysqli_stmt_bind_param($stmt, 'i', $editId);
        mysqli_stmt_execute($stmt);
        $result = mysqli_stmt_get_result($stmt);
        $company = mysqli_fetch_assoc($result);
        mysqli_stmt_close($stmt);

        if ($company) {
            $form = $company;
            $editing = true;
        } else {
            $error = 'Company not found.';
        }
    } else {
        $error = 'Invalid company ID.';
    }
}

// Search companies by name, location or industry.
$search = trim($_GET['search'] ?? '');

$sql = "SELECT c.company_id, c.company_name, c.location, c.industry,
               (SELECT COUNT(*) FROM Job j
                WHERE j.company_id = c.company_id) AS job_count
        FROM Company c
        WHERE 1=1";

if ($search !== '') {
    $sql .= ' AND (c.company_name LIKE ? OR c.location LIKE ? OR c.industry LIKE ?)';
}

$sql .= ' ORDER BY c.company_name ASC';

$stmt = mysqli_prepare($conn, $sql);

if ($search !== '') {
    $like = '%' . $search . '%';
    mysqli_stmt_bind_param($stmt, 'sss', $like, $like, $like);
}

mysqli_stmt_execute($stmt);
$companies = mysqli_stmt_get_result($stmt);

$totalResult = mysqli_query($conn, 'SELECT COUNT(*) AS total FROM Company');
$totalCompanies = (int)mysqli_fetch_assoc($totalResult)['total'];

$jobResult = mysqli_query($conn, 'SELECT COUNT(*) AS total FROM Job');
$totalJobs = (int)mysqli_fetch_assoc($jobResult)['total'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Companies | PlacementHub</title>
    <link rel="stylesheet" href="style.css">

    <style>
        .main { min-width: 0; }

        .company-stats {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 16px;
            margin-bottom: 22px;
        }

        .company-stat, .company-panel {
            background: #fff;
            border: 1px solid #e8edf5;
            border-radius: 15px;
            padding: 20px;
            box-shadow: 0 5px 20px rgba(15,23,42,.04);
        }

        .company-stat small { color: #64748b; }
        .company-stat strong {
            display: block;
            margin-top: 8px;
            font-size: 27px;
            color: #0f172a;
        }

        .company-panel { margin-bottom: 22px; }
        .company-panel h2 { margin: 0 0 18px; font-size: 19px; }

        .company-form {
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: 16px;
        }

        .field label {
            display: block;
            margin-bottom: 7px;
            color: #334155;
            font-size: 13px;
            font-weight: 600;
        }

        .field input, .search-input {
            width: 100%;
            box-sizing: border-box;
            padding: 11px 12px;
            border: 1px solid #cbd5e1;
            border-radius: 9px;
            background: white;
            font-size: 14px;
        }

        .field input:focus, .search-input:focus {
            outline: 2px solid #bfdbfe;
            border-color: #2563eb;
        }

        .form-actions {
            grid-column: 1 / -1;
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
        }

        .btn {
            display: inline-block;
            border: 0;
            border-radius: 9px;
            padding: 10px 14px;
            background: #2563eb;
            color: #fff;
            font-weight: 600;
            font-size: 13px;
            text-decoration: none;
            cursor: pointer;
        }

        .btn:hover { background: #1d4ed8; }
        .btn-secondary { background: #e2e8f0; color: #1e293b; }
        .btn-secondary:hover { background: #cbd5e1; }
        .btn-danger { background: #fee2e2; color: #b91c1c; }
        .btn-danger:hover { background: #fecaca; }

        .notice {
            padding: 13px 15px;
            margin-bottom: 18px;
            border-radius: 10px;
            font-size: 14px;
        }

        .notice-success { background: #dcfce7; color: #166534; }
        .notice-error { background: #fee2e2; color: #991b1b; }

        .search-bar {
            display: flex;
            gap: 10px;
            margin-bottom: 18px;
        }

        .search-input { flex: 1; min-width: 0; }

        .table-wrap { overflow-x: auto; width: 100%; }

        .company-table {
            width: 100%;
            border-collapse: collapse;
            text-align: left;
            font-size: 13px;
        }

        .company-table th {
            background: #f8fafc;
            color: #64748b;
            padding: 13px 12px;
            white-space: nowrap;
        }

        .company-table td {
            padding: 14px 12px;
            border-bottom: 1px solid #edf1f7;
            color: #334155;
        }

        .company-table tr:last-child td { border-bottom: 0; }

        .industry-pill {
            display: inline-block;
            background: #eff6ff;
            color: #1d4ed8;
            padding: 5px 9px;
            border-radius: 20px;
        }

        .actions { display: flex; gap: 7px; align-items: center; }
        .actions form { margin: 0; }
        .actions .btn { padding: 7px 10px; }
        .empty { padding: 25px; text-align: center; color: #64748b; }
        .muted { color: #64748b; font-size: 12px; }

        @media(max-width: 850px) {
            .company-form { grid-template-columns: repeat(2, minmax(0, 1fr)); }
        }

        @media(max-width: 600px) {
            .company-stats, .company-form { grid-template-columns: 1fr; }
            .search-bar { flex-direction: column; }
            .company-panel { padding: 15px; }
        }
    </style>
</head>
<body>
<div class="dashboard">
    <aside class="sidebar">
        <div class="logo">
            🎓 PlacementHub
            <span>College Placement System</span>
        </div>

        <a href="index.php">🏠 Dashboard</a>
        <a href="students.php">🎓 Students</a>
        <a href="companies.php" class="active">🏢 Companies</a>
        <a href="jobs.php">💼 Jobs</a>
        <a href="applications.php">📄 Applications</a>
        <a href="rounds.php">📝 Selection Rounds</a>
        <a href="placements.php">🏆 Placements</a>
    </aside>

    <main class="main">
        <div class="topbar">
            <div>
                <h1>Company Management</h1>
                <p class="subtitle">Register and manage campus recruiting companies</p>
            </div>
        </div>

        <?php
        $messages = [
            'added' => 'Company added successfully.',
            'updated' => 'Company details updated successfully.',
            'deleted' => 'Company deleted successfully.',
            'not_found' => 'Company record not found.',
            'invalid_id' => 'Invalid company ID.',
            'delete_blocked' => 'This company has related jobs and cannot be deleted. Remove or reassign those jobs first.',
            'save_error' => 'The operation could not be completed. Please try again.'
        ];

        if (isset($messages[$message])): ?>
            <div class="notice <?php echo in_array($message, ['added', 'updated', 'deleted'], true) ? 'notice-success' : 'notice-error'; ?>">
                <?php echo h($messages[$message]); ?>
            </div>
        <?php endif; ?>

        <?php if ($error !== ''): ?>
            <div class="notice notice-error"><?php echo h($error); ?></div>
        <?php endif; ?>

        <section class="company-stats">
            <div class="company-stat">
                <small>Total registered companies</small>
                <strong><?php echo number_format($totalCompanies); ?></strong>
            </div>
            <div class="company-stat">
                <small>Total jobs across all companies</small>
                <strong><?php echo number_format($totalJobs); ?></strong>
            </div>
        </section>

        <section class="company-panel">
            <h2><?php echo $editing ? '✏️ Edit Company' : '➕ Add New Company'; ?></h2>

            <form method="post" action="companies.php<?php echo $editing ? '?edit=' . (int)$form['company_id'] : ''; ?>">
                <input type="hidden" name="csrf_token" value="<?php echo h($_SESSION['placementhub_csrf']); ?>">
                <input type="hidden" name="action" value="<?php echo $editing ? 'update' : 'add'; ?>">

                <?php if ($editing): ?>
                    <input type="hidden" name="company_id" value="<?php echo (int)$form['company_id']; ?>">
                <?php endif; ?>

                <div class="company-form">
                    <div class="field">
                        <label for="company_name">Company name *</label>
                        <input id="company_name" name="company_name" maxlength="100" required value="<?php echo h($form['company_name']); ?>" placeholder="e.g. Infosys">
                    </div>

                    <div class="field">
                        <label for="location">Location</label>
                        <input id="location" name="location" maxlength="100" value="<?php echo h($form['location']); ?>" placeholder="e.g. Bengaluru">
                    </div>

                    <div class="field">
                        <label for="industry">Industry</label>
                        <input id="industry" name="industry" maxlength="100" value="<?php echo h($form['industry']); ?>" placeholder="e.g. IT Services">
                    </div>

                    <div class="form-actions">
                        <button class="btn" type="submit"><?php echo $editing ? 'Save Changes' : 'Add Company'; ?></button>
                        <?php if ($editing): ?>
                            <a class="btn btn-secondary" href="companies.php">Cancel Edit</a>
                        <?php endif; ?>
                    </div>
                </div>
            </form>
        </section>

        <section class="company-panel">
            <h2>Recruiting Companies</h2>

            <form method="get" action="companies.php" class="search-bar">
                <input class="search-input" type="search" name="search" value="<?php echo h($search); ?>" placeholder="Search company, location or industry">
                <button class="btn" type="submit">Search</button>
                <a class="btn btn-secondary" href="companies.php">Reset</a>
            </form>

            <div class="table-wrap">
                <table class="company-table">
                    <thead>
                    <tr>
                        <th>ID</th>
                        <th>Company</th>
                        <th>Location</th>
                        <th>Industry</th>
                        <th>Jobs</th>
                        <th>Actions</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php if (mysqli_num_rows($companies) > 0): ?>
                        <?php while ($row = mysqli_fetch_assoc($companies)): ?>
                            <tr>
                                <td><?php echo (int)$row['company_id']; ?></td>
                                <td><strong><?php echo h($row['company_name']); ?></strong></td>
                                <td><?php echo h($row['location'] ?: '—'); ?></td>
                                <td><span class="industry-pill"><?php echo h($row['industry'] ?: 'Not specified'); ?></span></td>
                                <td><?php echo (int)$row['job_count']; ?></td>
                                <td>
                                    <div class="actions">
                                        <a class="btn" href="companies.php?edit=<?php echo (int)$row['company_id']; ?>">Edit</a>

                                        <form method="post" action="companies.php" onsubmit="return confirm('Delete this company? Companies with related jobs cannot be deleted.');">
                                            <input type="hidden" name="csrf_token" value="<?php echo h($_SESSION['placementhub_csrf']); ?>">
                                            <input type="hidden" name="action" value="delete">
                                            <input type="hidden" name="company_id" value="<?php echo (int)$row['company_id']; ?>">
                                            <button class="btn btn-danger" type="submit">Delete</button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <tr><td colspan="6" class="empty">No companies found. Add a company above or try another search.</td></tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </section>
    </main>
</div>
</body>
</html>