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

function jobRedirect($message) {
    header('Location: jobs.php?message=' . urlencode($message));
    exit;
}

$form = [
    'job_id' => '',
    'company_id' => '',
    'job_role' => '',
    'package' => '',
    'min_cgpa' => '',
    'job_type' => 'Full-Time',
    'drive_date' => '',
    'deadline' => ''
];

$error = '';
$message = $_GET['message'] ?? '';
$editing = false;

// Handle add, update and delete requests.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['csrf_token'] ?? '';

    if (!hash_equals($_SESSION['placementhub_csrf'], $token)) {
        http_response_code(403);
        exit('Invalid request token. Refresh the page and try again.');
    }

    $action = $_POST['action'] ?? '';

    if ($action === 'delete') {
        $id = filter_var($_POST['job_id'] ?? '', FILTER_VALIDATE_INT);

        if (!$id || $id < 1) {
            jobRedirect('invalid_id');
        }

        $stmt = mysqli_prepare($conn, 'DELETE FROM Job WHERE job_id = ?');
        mysqli_stmt_bind_param($stmt, 'i', $id);
        $success = mysqli_stmt_execute($stmt);
        $errno = mysqli_stmt_errno($stmt);
        $affected = mysqli_stmt_affected_rows($stmt);
        mysqli_stmt_close($stmt);

        if ($success && $affected > 0) {
            jobRedirect('deleted');
        }

        if ($success) {
            jobRedirect('not_found');
        }

        if ($errno === 1451) {
            jobRedirect('delete_blocked');
        }

        error_log('PlacementHub job deletion failed. Error code: ' . $errno);
        jobRedirect('save_error');
    }

    if ($action === 'add' || $action === 'update') {
        $form['job_id'] = trim($_POST['job_id'] ?? '');
        $form['company_id'] = trim($_POST['company_id'] ?? '');
        $form['job_role'] = trim($_POST['job_role'] ?? '');
        $form['package'] = trim($_POST['package'] ?? '');
        $form['min_cgpa'] = trim($_POST['min_cgpa'] ?? '');
        $form['job_type'] = trim($_POST['job_type'] ?? '');
        $form['drive_date'] = trim($_POST['drive_date'] ?? '');
        $form['deadline'] = trim($_POST['deadline'] ?? '');

        $id = filter_var($form['job_id'], FILTER_VALIDATE_INT);
        $companyId = filter_var($form['company_id'], FILTER_VALIDATE_INT);

        if ($action === 'update' && (!$id || $id < 1)) {
            $error = 'Invalid job ID.';
        } elseif (!$companyId || $companyId < 1) {
            $error = 'Please select a company.';
        } elseif (
            $form['job_role'] === '' ||
            mb_strlen($form['job_role']) > 100
        ) {
            $error = 'Job role is required and must not exceed 100 characters.';
        } elseif (
            $form['package'] === '' ||
            !is_numeric($form['package']) ||
            (float)$form['package'] < 0 ||
            (float)$form['package'] > 99999999
        ) {
            $error = 'Enter a valid non-negative package.';
        } elseif (
            $form['min_cgpa'] === '' ||
            !is_numeric($form['min_cgpa']) ||
            (float)$form['min_cgpa'] < 0 ||
            (float)$form['min_cgpa'] > 10
        ) {
            $error = 'Minimum CGPA must be between 0 and 10.';
        } elseif (
            !in_array(
                $form['job_type'],
                ['Full-Time', 'Internship', 'Part-Time', 'Contract'],
                true
            )
        ) {
            $error = 'Select a valid job type.';
        }

        // A drive is optional. If either date is provided, both are required.
        $createDrive = ($form['drive_date'] !== '' || $form['deadline'] !== '');

        if ($error === '' && $createDrive) {
            $validDriveDate = preg_match('/^\d{4}-\d{2}-\d{2}$/', $form['drive_date'])
                && checkdate(
                    (int)substr($form['drive_date'], 5, 2),
                    (int)substr($form['drive_date'], 8, 2),
                    (int)substr($form['drive_date'], 0, 4)
                );

            $validDeadline = preg_match('/^\d{4}-\d{2}-\d{2}$/', $form['deadline'])
                && checkdate(
                    (int)substr($form['deadline'], 5, 2),
                    (int)substr($form['deadline'], 8, 2),
                    (int)substr($form['deadline'], 0, 4)
                );

            if (!$validDriveDate || !$validDeadline) {
                $error = 'Enter valid drive and application deadline dates.';
            } elseif ($form['deadline'] > $form['drive_date']) {
                $error = 'The application deadline must be on or before the drive date.';
            }
        }

        // Confirm that the company exists.
        if ($error === '') {
            $check = mysqli_prepare(
                $conn,
                'SELECT company_id FROM Company WHERE company_id = ?'
            );
            mysqli_stmt_bind_param($check, 'i', $companyId);
            mysqli_stmt_execute($check);
            mysqli_stmt_store_result($check);
            $companyExists = mysqli_stmt_num_rows($check) === 1;
            mysqli_stmt_close($check);

            if (!$companyExists) {
                $error = 'The selected company does not exist.';
            }
        }

        if ($error === '') {
            $role = $form['job_role'];
            $package = (float)$form['package'];
            $minCgpa = (float)$form['min_cgpa'];
            $jobType = $form['job_type'];

            mysqli_begin_transaction($conn);

            if ($action === 'add') {
                $stmt = mysqli_prepare(
                    $conn,
                    'INSERT INTO Job (company_id, job_role, package, min_cgpa, job_type)
                     VALUES (?, ?, ?, ?, ?)'
                );

                mysqli_stmt_bind_param(
                    $stmt,
                    'isdds',
                    $companyId,
                    $role,
                    $package,
                    $minCgpa,
                    $jobType
                );
            } else {
                $stmt = mysqli_prepare(
                    $conn,
                    'UPDATE Job
                     SET company_id = ?, job_role = ?, package = ?,
                         min_cgpa = ?, job_type = ?
                     WHERE job_id = ?'
                );

                mysqli_stmt_bind_param(
                    $stmt,
                    'isddsi',
                    $companyId,
                    $role,
                    $package,
                    $minCgpa,
                    $jobType,
                    $id
                );
            }

            $success = mysqli_stmt_execute($stmt);
            $errno = mysqli_stmt_errno($stmt);
            $savedJobId = $action === 'add' ? mysqli_insert_id($conn) : $id;
            mysqli_stmt_close($stmt);

            // Optionally create a placement drive for this job.
            if ($success && $createDrive) {
                $driveDate = $form['drive_date'];
                $deadline = $form['deadline'];

                $driveStmt = mysqli_prepare(
                    $conn,
                    'INSERT INTO Placement_Drive (job_id, drive_date, deadline)
                     VALUES (?, ?, ?)'
                );

                mysqli_stmt_bind_param(
                    $driveStmt,
                    'iss',
                    $savedJobId,
                    $driveDate,
                    $deadline
                );

                $driveSuccess = mysqli_stmt_execute($driveStmt);
                mysqli_stmt_close($driveStmt);

                if (!$driveSuccess) {
                    $success = false;
                }
            }

            if ($success) {
                mysqli_commit($conn);
                jobRedirect($action === 'add' ? 'added' : 'updated');
            }

            mysqli_rollback($conn);

            if ($errno === 1451 || $errno === 1452) {
                $error = 'The job could not be saved because a related database record is missing or linked.';
            } else {
                error_log('PlacementHub job save failed. Error code: ' . $errno);
                $error = 'Could not save the job or its placement drive. Please try again.';
            }
        }

        $editing = ($action === 'update');
    }
}

// Load a job for editing.
if ($_SERVER['REQUEST_METHOD'] !== 'POST' && isset($_GET['edit'])) {
    $editId = filter_var($_GET['edit'], FILTER_VALIDATE_INT);

    if ($editId && $editId > 0) {
        $stmt = mysqli_prepare(
            $conn,
            'SELECT job_id, company_id, job_role, package, min_cgpa, job_type
             FROM Job WHERE job_id = ?'
        );

        mysqli_stmt_bind_param($stmt, 'i', $editId);
        mysqli_stmt_execute($stmt);
        $result = mysqli_stmt_get_result($stmt);
        $job = mysqli_fetch_assoc($result);
        mysqli_stmt_close($stmt);

        if ($job) {
            $form = array_merge($form, $job);
            $editing = true;
        } else {
            $error = 'Job not found.';
        }
    } else {
        $error = 'Invalid job ID.';
    }
}

// Company options for the form.
$companyResult = mysqli_query(
    $conn,
    'SELECT company_id, company_name FROM Company ORDER BY company_name'
);

if (!$companyResult) {
    error_log('PlacementHub company list query failed: ' . mysqli_error($conn));
    exit('Unable to load companies.');
}

$companyList = [];
while ($company = mysqli_fetch_assoc($companyResult)) {
    $companyList[] = $company;
}

// Search and filters.
$search = trim($_GET['search'] ?? '');
$companyFilter = trim($_GET['company'] ?? '');
$typeFilter = trim($_GET['type'] ?? '');

$sql = "SELECT j.job_id, j.company_id, c.company_name, j.job_role,
               j.package, j.min_cgpa, j.job_type,
               (SELECT COUNT(*) FROM Placement_Drive pd
                WHERE pd.job_id = j.job_id) AS drive_count
        FROM Job j
        JOIN Company c ON c.company_id = j.company_id
        WHERE 1=1";

$types = '';
$params = [];

if ($search !== '') {
    $sql .= ' AND (j.job_role LIKE ? OR c.company_name LIKE ?)';
    $like = '%' . $search . '%';
    $types .= 'ss';
    $params[] = $like;
    $params[] = $like;
}

if ($companyFilter !== '' && ctype_digit($companyFilter) && (int)$companyFilter > 0) {
    $sql .= ' AND j.company_id = ?';
    $types .= 'i';
    $params[] = (int)$companyFilter;
}

if (in_array($typeFilter, ['Full-Time', 'Internship', 'Part-Time', 'Contract'], true)) {
    $sql .= ' AND j.job_type = ?';
    $types .= 's';
    $params[] = $typeFilter;
}

$sql .= ' ORDER BY j.job_id DESC';

$stmt = mysqli_prepare($conn, $sql);

if ($types !== '') {
    mysqli_stmt_bind_param($stmt, $types, ...$params);
}

mysqli_stmt_execute($stmt);
$jobs = mysqli_stmt_get_result($stmt);

// Live statistics.
$countResult = mysqli_query($conn, 'SELECT COUNT(*) AS total FROM Job');
$totalJobs = (int)mysqli_fetch_assoc($countResult)['total'];

$packageResult = mysqli_query($conn, 'SELECT MAX(package) AS highest FROM Job');
$packageRow = mysqli_fetch_assoc($packageResult);
$highestPackage = $packageRow['highest'] !== null
    ? number_format((float)$packageRow['highest'], 2)
    : '0.00';

$driveCountResult = mysqli_query($conn, 'SELECT COUNT(*) AS total FROM Placement_Drive');
$totalDrives = (int)mysqli_fetch_assoc($driveCountResult)['total'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Jobs | PlacementHub</title>
    <link rel="stylesheet" href="style.css">

    <style>
        .main { min-width: 0; }

        .job-stats {
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: 16px;
            margin-bottom: 22px;
        }

        .job-stat, .job-panel {
            background: #fff;
            border: 1px solid #e8edf5;
            border-radius: 15px;
            padding: 20px;
            box-shadow: 0 5px 20px rgba(15,23,42,.04);
        }

        .job-stat small { color: #64748b; }
        .job-stat strong {
            display: block;
            margin-top: 8px;
            font-size: 27px;
            color: #0f172a;
        }

        .job-panel { margin-bottom: 22px; }
        .job-panel h2 { margin: 0 0 18px; font-size: 19px; }

        .job-form {
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

        .field input, .field select, .search-input {
            width: 100%;
            box-sizing: border-box;
            padding: 11px 12px;
            border: 1px solid #cbd5e1;
            border-radius: 9px;
            background: white;
            font-size: 14px;
        }

        .field input:focus, .field select:focus, .search-input:focus {
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
            display: grid;
            grid-template-columns: minmax(180px, 1fr) minmax(140px, .7fr) minmax(140px, .7fr) auto auto;
            gap: 10px;
            margin-bottom: 18px;
        }

        .table-wrap { overflow-x: auto; width: 100%; }

        .job-table {
            width: 100%;
            border-collapse: collapse;
            text-align: left;
            font-size: 13px;
        }

        .job-table th {
            background: #f8fafc;
            color: #64748b;
            padding: 13px 12px;
            white-space: nowrap;
        }

        .job-table td {
            padding: 14px 12px;
            border-bottom: 1px solid #edf1f7;
            color: #334155;
        }

        .job-table tr:last-child td { border-bottom: 0; }

        .package-pill {
            display: inline-block;
            background: #dcfce7;
            color: #166534;
            padding: 5px 9px;
            border-radius: 20px;
            font-weight: 700;
            white-space: nowrap;
        }

        .type-pill {
            display: inline-block;
            background: #eff6ff;
            color: #1d4ed8;
            padding: 5px 9px;
            border-radius: 20px;
            white-space: nowrap;
        }

        .actions { display: flex; gap: 7px; align-items: center; }
        .actions form { margin: 0; }
        .actions .btn { padding: 7px 10px; }
        .muted { color: #64748b; font-size: 12px; }
        .empty { padding: 25px; text-align: center; color: #64748b; }

        .drive-help {
            grid-column: 1 / -1;
            color: #64748b;
            font-size: 12px;
            line-height: 1.5;
        }

        @media(max-width: 1050px) {
            .search-bar { grid-template-columns: 1fr 1fr; }
        }

        @media(max-width: 800px) {
            .job-form { grid-template-columns: repeat(2, minmax(0, 1fr)); }
            .job-stats { grid-template-columns: 1fr; }
        }

        @media(max-width: 550px) {
            .job-form, .search-bar { grid-template-columns: 1fr; }
            .job-panel { padding: 15px; }
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
        <a href="companies.php">🏢 Companies</a>
        <a href="jobs.php" class="active">💼 Jobs</a>
        <a href="applications.php">📄 Applications</a>
        <a href="rounds.php">📝 Selection Rounds</a>
        <a href="placements.php">🏆 Placements</a>
    </aside>

    <main class="main">
        <div class="topbar">
            <div>
                <h1>Job Management</h1>
                <p class="subtitle">Manage job opportunities, eligibility and placement drives</p>
            </div>
        </div>

        <?php
        $messages = [
            'added' => 'Job added successfully.',
            'updated' => 'Job updated successfully.',
            'deleted' => 'Job deleted successfully.',
            'not_found' => 'Job record not found.',
            'invalid_id' => 'Invalid job ID.',
            'delete_blocked' => 'This job has related placement drives or applications and cannot be deleted.',
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

        <section class="job-stats">
            <div class="job-stat">
                <small>Total jobs</small>
                <strong><?php echo number_format($totalJobs); ?></strong>
            </div>
            <div class="job-stat">
                <small>Highest package</small>
                <strong>₹<?php echo h($highestPackage); ?> LPA</strong>
            </div>
            <div class="job-stat">
                <small>Placement drives</small>
                <strong><?php echo number_format($totalDrives); ?></strong>
            </div>
        </section>

        <section class="job-panel">
            <h2><?php echo $editing ? '✏️ Edit Job' : '➕ Add New Job'; ?></h2>

            <form method="post" action="jobs.php<?php echo $editing ? '?edit=' . (int)$form['job_id'] : ''; ?>">
                <input type="hidden" name="csrf_token" value="<?php echo h($_SESSION['placementhub_csrf']); ?>">
                <input type="hidden" name="action" value="<?php echo $editing ? 'update' : 'add'; ?>">

                <?php if ($editing): ?>
                    <input type="hidden" name="job_id" value="<?php echo (int)$form['job_id']; ?>">
                <?php endif; ?>

                <div class="job-form">
                    <div class="field">
                        <label for="company_id">Company *</label>
                        <select id="company_id" name="company_id" required>
                            <option value="">Select company</option>
                            <?php foreach ($companyList as $company): ?>
                                <option value="<?php echo (int)$company['company_id']; ?>"
                                    <?php echo (string)$form['company_id'] === (string)$company['company_id'] ? 'selected' : ''; ?>>
                                    <?php echo h($company['company_name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="field">
                        <label for="job_role">Job role *</label>
                        <input id="job_role" name="job_role" maxlength="100" required value="<?php echo h($form['job_role']); ?>" placeholder="e.g. Software Developer">
                    </div>

                    <div class="field">
                        <label for="package">Package (LPA) *</label>
                        <input id="package" type="number" name="package" min="0" max="99999999" step="0.01" required value="<?php echo h($form['package']); ?>" placeholder="e.g. 7.50">
                    </div>

                    <div class="field">
                        <label for="min_cgpa">Minimum CGPA *</label>
                        <input id="min_cgpa" type="number" name="min_cgpa" min="0" max="10" step="0.01" required value="<?php echo h($form['min_cgpa']); ?>" placeholder="e.g. 7.00">
                    </div>

                    <div class="field">
                        <label for="job_type">Job type *</label>
                        <select id="job_type" name="job_type" required>
                            <?php foreach (['Full-Time', 'Internship', 'Part-Time', 'Contract'] as $type): ?>
                                <option value="<?php echo h($type); ?>" <?php echo $form['job_type'] === $type ? 'selected' : ''; ?>>
                                    <?php echo h($type); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="field">
                        <label for="drive_date">Drive date (optional)</label>
                        <input id="drive_date" type="date" name="drive_date" value="<?php echo h($form['drive_date']); ?>">
                    </div>

                    <div class="field">
                        <label for="deadline">Application deadline (optional)</label>
                        <input id="deadline" type="date" name="deadline" value="<?php echo h($form['deadline']); ?>">
                    </div>

                    <p class="drive-help">
                        To create a placement drive, provide both dates. The application deadline must be on or before the drive date. Leaving both blank will save only the job. Saving a job edit with both dates filled creates an additional drive; it does not edit existing drives.
                    </p>

                    <div class="form-actions">
                        <button class="btn" type="submit"><?php echo $editing ? 'Save Changes' : 'Add Job'; ?></button>
                        <?php if ($editing): ?>
                            <a class="btn btn-secondary" href="jobs.php">Cancel Edit</a>
                        <?php endif; ?>
                    </div>
                </div>
            </form>
        </section>

        <section class="job-panel">
            <h2>Available Job Positions</h2>

            <form method="get" action="jobs.php" class="search-bar">
                <input class="search-input" type="search" name="search" value="<?php echo h($search); ?>" placeholder="Search job role or company">

                <select class="search-input" name="company">
                    <option value="">All companies</option>
                    <?php foreach ($companyList as $company): ?>
                        <option value="<?php echo (int)$company['company_id']; ?>"
                            <?php echo $companyFilter === (string)$company['company_id'] ? 'selected' : ''; ?>>
                            <?php echo h($company['company_name']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>

                <select class="search-input" name="type">
                    <option value="">All job types</option>
                    <?php foreach (['Full-Time', 'Internship', 'Part-Time', 'Contract'] as $type): ?>
                        <option value="<?php echo h($type); ?>" <?php echo $typeFilter === $type ? 'selected' : ''; ?>>
                            <?php echo h($type); ?>
                        </option>
                    <?php endforeach; ?>
                </select>

                <button class="btn" type="submit">Filter</button>
                <a class="btn btn-secondary" href="jobs.php">Reset</a>
            </form>

            <div class="table-wrap">
                <table class="job-table">
                    <thead>
                    <tr>
                        <th>ID</th>
                        <th>Company</th>
                        <th>Job Role</th>
                        <th>Package</th>
                        <th>Minimum CGPA</th>
                        <th>Job Type</th>
                        <th>Drives</th>
                        <th>Actions</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php if (mysqli_num_rows($jobs) > 0): ?>
                        <?php while ($row = mysqli_fetch_assoc($jobs)): ?>
                            <tr>
                                <td><?php echo (int)$row['job_id']; ?></td>
                                <td><strong><?php echo h($row['company_name']); ?></strong></td>
                                <td><?php echo h($row['job_role']); ?></td>
                                <td><span class="package-pill">₹<?php echo h(number_format((float)$row['package'], 2)); ?> LPA</span></td>
                                <td><?php echo h(number_format((float)$row['min_cgpa'], 2)); ?></td>
                                <td><span class="type-pill"><?php echo h($row['job_type']); ?></span></td>
                                <td><?php echo (int)$row['drive_count']; ?></td>
                                <td>
                                    <div class="actions">
                                        <a class="btn" href="jobs.php?edit=<?php echo (int)$row['job_id']; ?>">Edit</a>

                                        <form method="post" action="jobs.php" onsubmit="return confirm('Delete this job? Jobs with related drives or applications cannot be deleted.');">
                                            <input type="hidden" name="csrf_token" value="<?php echo h($_SESSION['placementhub_csrf']); ?>">
                                            <input type="hidden" name="action" value="delete">
                                            <input type="hidden" name="job_id" value="<?php echo (int)$row['job_id']; ?>">
                                            <button class="btn btn-danger" type="submit">Delete</button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <tr><td colspan="8" class="empty">No jobs found. Add a job above or change your filters.</td></tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </section>
    </main>
</div>
</body>
</html>