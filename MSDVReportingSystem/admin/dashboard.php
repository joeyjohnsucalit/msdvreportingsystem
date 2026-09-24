<?php
session_start();
require_once '../db/connection.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header('Location: ../login.php');
    exit;
}

header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Pragma: no-cache");
header("Expires: Sat, 01 Jan 2000 00:00:00 GMT");

$currentYear = date('Y');

$totalStudents    = $pdo->query("SELECT COUNT(*) FROM students")->fetchColumn();
$totalViolations  = $pdo->query("SELECT COUNT(*) FROM violations")->fetchColumn();
$pendingSanctions = $pdo->query("SELECT COUNT(*) FROM disciplinary_actions WHERE status='pending'")->fetchColumn();
$completedCases   = $pdo->query("SELECT COUNT(*) FROM disciplinary_actions WHERE status='completed'")->fetchColumn();

$unreadStmt = $pdo->prepare("
    SELECT COUNT(*)
    FROM notifications
    WHERE user_id=? AND is_read=0
");
$unreadStmt->execute([$_SESSION['user_id']]);
$unreadCount = (int)$unreadStmt->fetchColumn();

$notifStmt = $pdo->prepare("
    SELECT *
    FROM notifications
    WHERE user_id=?
    ORDER BY created_at DESC
    LIMIT 20
");
$notifStmt->execute([$_SESSION['user_id']]);
$notifList = $notifStmt->fetchAll();

/* =========================
   MONTHLY VIOLATIONS
========================= */

$vpm = $pdo->prepare("
    SELECT MONTH(date_submitted) m, COUNT(*) c
    FROM violations
    WHERE YEAR(date_submitted)=?
    GROUP BY m
");
$vpm->execute([$currentYear]);

$vpmData = array_fill(0, 12, 0);

foreach ($vpm->fetchAll() as $r) {
    $vpmData[$r['m'] - 1] = (int)$r['c'];
}

/* =========================
   MINOR VS MAJOR
========================= */

$minorData = array_fill(0, 12, 0);
$majorData = array_fill(0, 12, 0);

$s1 = $pdo->prepare("
    SELECT MONTH(date_submitted) m, COUNT(*) c
    FROM violations
    WHERE YEAR(date_submitted)=?
    AND category='minor'
    GROUP BY m
");
$s1->execute([$currentYear]);

foreach ($s1->fetchAll() as $r) {
    $minorData[$r['m'] - 1] = (int)$r['c'];
}

$s2 = $pdo->prepare("
    SELECT MONTH(date_submitted) m, COUNT(*) c
    FROM violations
    WHERE YEAR(date_submitted)=?
    AND category='major'
    GROUP BY m
");
$s2->execute([$currentYear]);

foreach ($s2->fetchAll() as $r) {
    $majorData[$r['m'] - 1] = (int)$r['c'];
}

/* =========================
   DEPARTMENT COLORS
========================= */

$deptColorMap = [
    'School of Technology' => '#e05454',
    'School of Education'  => '#4f8ef7',
    'School of Business'   => '#f4973a'
];

/* =========================
   VIOLATIONS BY DEPARTMENT
========================= */

$vbd = $pdo->prepare("
    SELECT
        d.name,
        COUNT(v.id) c
    FROM violations v
    JOIN students s
        ON v.student_id=s.student_id
    JOIN departments d
        ON s.department_id=d.id
    WHERE YEAR(v.date_submitted)=?
    GROUP BY d.id
");
$vbd->execute([$currentYear]);

$deptLabels = [];
$deptData   = [];
$deptColors = [];

foreach ($vbd->fetchAll() as $r) {
    $deptLabels[] = $r['name'];
    $deptData[]   = (int)$r['c'];
    $deptColors[] = $deptColorMap[$r['name']] ?? '#aab0c4';
}

/* =========================
   VIOLATIONS BY COURSE
========================= */

$vbc = $pdo->prepare("
    SELECT
        c.name cn,
        d.name dn,
        COUNT(v.id) c
    FROM violations v
    JOIN students s
        ON v.student_id=s.student_id
    JOIN courses c
        ON s.course_id=c.id
    JOIN departments d
        ON s.department_id=d.id
    WHERE YEAR(v.date_submitted)=?
    GROUP BY c.id
    ORDER BY d.id
");
$vbc->execute([$currentYear]);

$courseLabels = [];
$courseData   = [];
$courseColors = [];

foreach ($vbc->fetchAll() as $r) {
    $courseLabels[] = $r['cn'];
    $courseData[]   = (int)$r['c'];
    $courseColors[] = $deptColorMap[$r['dn']] ?? '#aab0c4';
}

/* =========================
   AVAILABLE YEARS
========================= */

$years = $pdo->query("
    SELECT DISTINCT YEAR(date_submitted) y
    FROM violations
    ORDER BY y DESC
")->fetchAll(PDO::FETCH_COLUMN);

if (empty($years)) {
    $years = [date('Y')];
}

/* =========================
   VIOLATION LIST
========================= */

$violations_list = [
    'Disruptive Behavior',
    'Littering',
    'Dress Code',
    'Unapproved Absences',
    'Inappropriate Language',
    'Unauthorized Use of College Property',
    'Smoking on Campus',
    'Failure to Display ID',
    'Noise Violations',
    'Minor Vandalism',
    'Academic Dishonesty',
    'Theft',
    'Physical Violence',
    'Substance Abuse',
    'Harassment',
    'Unauthorized Entry',
    'Forgery',
    'Moral Infractions',
    'Weapons Possession',
    'Cyber Bullying',
    'Hazing',
    'Major Dishonesty',
    'Extortion',
    'Sexual Misconduct'
];
?>

<!DOCTYPE html>
<html lang="en">

<head>

    <meta charset="UTF-8">

    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>MSDV | Dashboard</title>

    <!-- Font Awesome -->
    <link
        rel="stylesheet"
        href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css"
    >

    <!-- Bootstrap -->
    <link
        rel="stylesheet"
        href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css"
    >

    <!-- Google Font -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>

    <link
        href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600&display=swap"
        rel="stylesheet"
    >

    <!-- Custom CSS -->
    <link rel="stylesheet" href="../assets/css/admin_dashboard.css">

</head>

<body>

<!-- =========================================================
     SIDEBAR
========================================================= -->

<div class="sidebar">

    <div class="adm_logo">

        <img
            src="../assets/images/mccLogo.png"
            alt="MCC Logo"
        >

        <div class="logotext">

            <h2>Admin Panel</h2>

            <p>MCC Student Violation System</p>

        </div>

    </div>


    <div class="sidebar_content">

        <ul>

            <!-- Overview -->

            <h3>Overview</h3>

            <li>
                <a href="dashboard.php" class="active">
                    <i class="fas fa-chart-line"></i>
                    <span>Dashboard</span>
                </a>
            </li>


            <!-- Students -->

            <h3>Students</h3>

            <li>
                <a href="student-records.php">
                    <i class="fas fa-user-graduate"></i>
                    <span>Student Records</span>
                </a>
            </li>

            <li>
                <a href="violation-records.php">
                    <i class="fas fa-history"></i>
                    <span>Violation Records</span>
                </a>
            </li>

            <li>
                <a href="student-appeals.php">
                    <i class="fa-solid fa-pen"></i>
                    <span>Student Appeals</span>
                </a>
            </li>


            <!-- Discipline -->

            <h3>Discipline</h3>

            <li>
                <a href="disciplinary-action.php">
                    <i class="fas fa-gavel"></i>
                    <span>Disciplinary Actions</span>
                </a>
            </li>

            <li>
                <a href="risk-level.php">
                    <i class="fas fa-exclamation-triangle"></i>
                    <span>Risk Level Indicator</span>
                </a>
            </li>


            <!-- Backup & Reports -->

            <h3>Backup &amp; Reports</h3>

            <li>
                <a href="data-backup.php">
                    <i class="fas fa-cloud-download-alt"></i>
                    <span>Data Backup</span>
                </a>
            </li>


            <!-- System -->

            <h3>System</h3>

            <li>
                <a href="user-management.php">
                    <i class="fas fa-user"></i>
                    <span>User Management</span>
                </a>
            </li>

            <li>
                <a href="../logout.php">
                    <i class="fas fa-sign-out-alt"></i>
                    <span>Log Out</span>
                </a>
            </li>

        </ul>

    </div>

</div>


<!-- =========================================================
     MAIN CONTENT
========================================================= -->

<div class="main-content">


    <!-- =====================================================
         NAVBAR
    ====================================================== -->

    <nav class="navbar">

        <h1>Dashboard</h1>


        <div class="navbar-right">


            <!-- Notification -->

            <div class="dropdown bell-wrap">

                <button
                    class="bell-btn"
                    id="bellBtn"
                    data-bs-toggle="dropdown"
                    data-bs-offset="0,8"
                    aria-expanded="false"
                >

                    <i class="fas fa-bell"></i>

                    <?php if ($unreadCount > 0): ?>

                        <span
                            class="notif-badge"
                            id="notifBadge"
                        >
                            <?= $unreadCount ?>
                        </span>

                    <?php endif; ?>

                </button>


                <!-- Notification Dropdown -->

                <div class="dropdown-menu dropdown-menu-end notif-dropdown p-0">


                    <!-- Notification Header -->

                    <div class="notif-head">

                        <div class="notif-head-left">

                            <span>Notifications</span>

                            <span
                                class="unread-pill <?= $unreadCount > 0 ? 'visible' : '' ?>"
                                id="notifPill"
                            >
                                <?= $unreadCount ?> new
                            </span>

                        </div>


                        <button
                            class="mark-all-btn"
                            onclick="markAllRead()"
                        >
                            Mark all read
                        </button>

                    </div>


                    <!-- Notification List -->

                    <div
                        class="notif-scroll"
                        id="notifList"
                    >

                        <?php if (empty($notifList)): ?>

                            <div class="notif-empty">

                                <i class="fas fa-bell-slash"></i>

                                <p>You're all caught up</p>

                            </div>

                        <?php else: ?>

                            <?php foreach ($notifList as $n): ?>

                                <a
                                    class="notif-item <?= !$n['is_read'] ? 'unread' : '' ?>"
                                    href="<?= htmlspecialchars($n['link'] ?? '#') ?>"
                                    onclick="markRead(<?= $n['id'] ?>, this); return true;"
                                >

                                    <div class="notif-avatar">

                                        <i class="fas fa-exclamation"></i>

                                    </div>


                                    <div class="notif-content">

                                        <div class="notif-text">

                                            <?= htmlspecialchars($n['message']) ?>

                                        </div>


                                        <div class="notif-ts">

                                            <i
                                                class="fas fa-clock"
                                                style="margin-right:3px;font-size:9px;"
                                            ></i>

                                            <?= date(
                                                'M d, Y · h:i A',
                                                strtotime($n['created_at'])
                                            ) ?>

                                        </div>

                                    </div>


                                    <?php if (!$n['is_read']): ?>

                                        <div class="notif-dot"></div>

                                    <?php endif; ?>

                                </a>

                            <?php endforeach; ?>

                        <?php endif; ?>

                    </div>

                </div>

            </div>


            <!-- User -->

            <div class="user-chip">

                <div class="user-avatar">

                    <?= strtoupper(
                        substr($_SESSION['full_name'], 0, 1)
                    ) ?>

                </div>

                <span class="user-name">

                    <?= htmlspecialchars($_SESSION['full_name']) ?>

                </span>

            </div>

        </div>

    </nav>


    <!-- =====================================================
         PAGE CONTENT
    ====================================================== -->

    <div class="content">


        <!-- =================================================
             KEY METRICS
        ================================================== -->

        <div class="sec-head">
            Key Metrics
        </div>


        <div class="kpi-row">


            <!-- Students -->

            <div class="kpi">

                <div class="kpi-row-top">

                    <span class="kpi-lbl">
                        Students
                    </span>

                    <div class="kpi-icon blue">
                        <i class="fas fa-user-graduate"></i>
                    </div>

                </div>


                <div class="kpi-num blue">
                    <?= number_format($totalStudents) ?>
                </div>


                <div class="kpi-sub">
                    Total enrolled
                </div>

            </div>


            <!-- Violations -->

            <div class="kpi">

                <div class="kpi-row-top">

                    <span class="kpi-lbl">
                        Violations
                    </span>

                    <div class="kpi-icon red">
                        <i class="fas fa-exclamation-triangle"></i>
                    </div>

                </div>


                <div class="kpi-num red">
                    <?= number_format($totalViolations) ?>
                </div>


                <div class="kpi-sub">
                    All recorded
                </div>

            </div>


            <!-- Pending -->

            <div class="kpi">

                <div class="kpi-row-top">

                    <span class="kpi-lbl">
                        Pending
                    </span>

                    <div class="kpi-icon amber">
                        <i class="fas fa-hourglass-half"></i>
                    </div>

                </div>


                <div class="kpi-num amber">
                    <?= number_format($pendingSanctions) ?>
                </div>


                <div class="kpi-sub">
                    Awaiting action
                </div>

            </div>


            <!-- Completed -->

            <div class="kpi">

                <div class="kpi-row-top">

                    <span class="kpi-lbl">
                        Completed
                    </span>

                    <div class="kpi-icon green">
                        <i class="fas fa-check-circle"></i>
                    </div>

                </div>


                <div class="kpi-num green">
                    <?= number_format($completedCases) ?>
                </div>


                <div class="kpi-sub">
                    Cases resolved
                </div>

            </div>

        </div>


        <!-- =================================================
             VIOLATION TRENDS
        ================================================== -->

        <div class="sec-head">
            Violation Trends
        </div>


        <div class="grid-2">


            <!-- Monthly Violations -->

            <div class="card">

                <div class="card-head">

                    <div>

                        <p class="card-title">
                            Monthly Violations
                        </p>

                        <p class="card-sub">
                            Recorded events per month
                        </p>

                    </div>


                    <div class="controls">

                        <select
                            class="yr-sel"
                            onchange="updateVPM(this.value)"
                        >

                            <?php foreach ($years as $y): ?>

                                <option
                                    value="<?= $y ?>"
                                    <?= $y == $currentYear ? 'selected' : '' ?>
                                >
                                    <?= $y ?>
                                </option>

                            <?php endforeach; ?>

                        </select>

                    </div>

                </div>


                <canvas
                    id="vpmChart"
                    height="165"
                ></canvas>

            </div>


            <!-- Minor vs Major -->

            <div class="card">

                <div class="card-head">

                    <div>

                        <p class="card-title">
                            Minor vs Major
                        </p>

                        <p class="card-sub">
                            Offense type by month
                        </p>

                    </div>


                    <div class="controls">

                        <select
                            class="yr-sel"
                            onchange="updateMVM(this.value)"
                        >

                            <?php foreach ($years as $y): ?>

                                <option
                                    value="<?= $y ?>"
                                    <?= $y == $currentYear ? 'selected' : '' ?>
                                >
                                    <?= $y ?>
                                </option>

                            <?php endforeach; ?>

                        </select>

                    </div>

                </div>


                <div class="chart-legend">

                    <div class="leg">

                        <div
                            class="leg-dot"
                            style="background:#f59e0b;"
                        ></div>

                        Minor

                    </div>


                    <div class="leg">

                        <div
                            class="leg-dot"
                            style="background:#ef4444;"
                        ></div>

                        Major

                    </div>

                </div>


                <canvas
                    id="mvmChart"
                    height="150"
                ></canvas>

            </div>

        </div>


        <!-- =================================================
             DEPARTMENT & COURSE
        ================================================== -->

        <div class="sec-head">
            Department &amp; Course
        </div>


        <div class="grid-2">


            <!-- Department -->

            <div class="card">

                <div class="card-head">

                    <div>

                        <p class="card-title">
                            By Department
                        </p>

                        <p class="card-sub">
                            Violations per department
                        </p>

                    </div>


                    <div class="controls">

                        <select
                            class="yr-sel"
                            onchange="updateDept(this.value)"
                        >

                            <?php foreach ($years as $y): ?>

                                <option
                                    value="<?= $y ?>"
                                    <?= $y == $currentYear ? 'selected' : '' ?>
                                >
                                    <?= $y ?>
                                </option>

                            <?php endforeach; ?>

                        </select>

                    </div>

                </div>


                <canvas
                    id="deptChart"
                    height="165"
                ></canvas>

            </div>


            <!-- Course -->

            <div class="card">

                <div class="card-head">

                    <div>

                        <p class="card-title">
                            By Course
                        </p>

                        <p class="card-sub">
                            Violations per course
                        </p>

                    </div>


                    <div class="controls">

                        <select
                            class="yr-sel"
                            onchange="updateCourse(this.value)"
                        >

                            <?php foreach ($years as $y): ?>

                                <option
                                    value="<?= $y ?>"
                                    <?= $y == $currentYear ? 'selected' : '' ?>
                                >
                                    <?= $y ?>
                                </option>

                            <?php endforeach; ?>

                        </select>

                    </div>

                </div>


                <canvas
                    id="courseChart"
                    height="165"
                ></canvas>

            </div>

        </div>


        <!-- =================================================
             SPECIFIC VIOLATION TRACKER
        ================================================== -->

        <div class="sec-head">
            Specific Violation Tracker
        </div>


        <div class="grid-1">

            <div class="card">


                <div class="card-head">

                    <div>

                        <p class="card-title">
                            Violation by Department
                        </p>

                        <p class="card-sub">
                            Monthly breakdown by offense type and department
                        </p>

                    </div>


                    <div class="controls">


                        <!-- Year -->

                        <select
                            class="yr-sel"
                            id="svYear"
                            onchange="updateSV()"
                        >

                            <?php foreach ($years as $y): ?>

                                <option
                                    value="<?= $y ?>"
                                    <?= $y == $currentYear ? 'selected' : '' ?>
                                >
                                    <?= $y ?>
                                </option>

                            <?php endforeach; ?>

                        </select>


                        <!-- Violation -->

                        <select
                            class="yr-sel wide"
                            id="svViolation"
                            onchange="updateSV()"
                        >

                            <?php foreach ($violations_list as $vl): ?>

                                <option
                                    value="<?= htmlspecialchars($vl) ?>"
                                >
                                    <?= htmlspecialchars($vl) ?>
                                </option>

                            <?php endforeach; ?>

                        </select>

                    </div>

                </div>


                <!-- Legend -->

                <div class="chart-legend">

                    <div class="leg">

                        <div
                            class="leg-dot"
                            style="background:#e05454;"
                        ></div>

                        School of Technology

                    </div>


                    <div class="leg">

                        <div
                            class="leg-dot"
                            style="background:#4f8ef7;"
                        ></div>

                        School of Education

                    </div>


                    <div class="leg">

                        <div
                            class="leg-dot"
                            style="background:#f4973a;"
                        ></div>

                        School of Business

                    </div>

                </div>


                <canvas
                    id="svChart"
                    height="88"
                ></canvas>

            </div>

        </div>

    </div>

</div>


<!-- =========================================================
     JAVASCRIPT LIBRARIES
========================================================= -->

<script
    src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js">
</script>

<script
    src="https://cdn.jsdelivr.net/npm/chart.js@4.4.3/dist/chart.umd.min.js">
</script>


<!-- =========================================================
     DASHBOARD JAVASCRIPT
========================================================= -->

<script>

const months = [
    'Jan',
    'Feb',
    'Mar',
    'Apr',
    'May',
    'Jun',
    'Jul',
    'Aug',
    'Sep',
    'Oct',
    'Nov',
    'Dec'
];


Chart.defaults.font.family = "'Plus Jakarta Sans', sans-serif";
Chart.defaults.font.size = 11;
Chart.defaults.color = '#9ca3af';


const G = '#f3f4f6';


/* =========================================================
   SHARED TOOLTIP
========================================================= */

const tip = {

    backgroundColor: '#111827',

    titleColor: '#f9fafb',

    bodyColor: '#d1d5db',

    padding: 10,

    cornerRadius: 8,

    titleFont: {
        weight: '600',
        size: 12
    },

    bodyFont: {
        size: 11
    },

    displayColors: true,

    boxWidth: 8,

    boxHeight: 8

};


/* =========================================================
   SHARED SCALES
========================================================= */

const scalesBase = {

    y: {

        beginAtZero: true,

        grid: {
            color: G,
            lineWidth: 1
        },

        border: {
            display: false
        },

        ticks: {
            padding: 6,
            maxTicksLimit: 6
        }

    },

    x: {

        grid: {
            display: false
        },

        border: {
            display: false
        },

        ticks: {
            padding: 4
        }

    }

};


/* =========================================================
   MONTHLY VIOLATIONS
========================================================= */

let vpmChart = new Chart(
    document.getElementById('vpmChart'),
    {

        type: 'bar',

        data: {

            labels: months,

            datasets: [

                {

                    label: 'Violations',

                    data: <?= json_encode($vpmData) ?>,

                    backgroundColor: (ctx) => {

                        const chart = ctx.chart;

                        const {
                            ctx: c,
                            chartArea
                        } = chart;

                        if (!chartArea) {
                            return '#c7d7f8';
                        }

                        const grad =
                            c.createLinearGradient(
                                0,
                                chartArea.top,
                                0,
                                chartArea.bottom
                            );

                        grad.addColorStop(
                            0,
                            '#3b82f6'
                        );

                        grad.addColorStop(
                            1,
                            '#c7d7f8'
                        );

                        return grad;

                    },

                    borderColor: 'transparent',

                    borderRadius: {
                        topLeft: 5,
                        topRight: 5,
                        bottomLeft: 0,
                        bottomRight: 0
                    },

                    borderSkipped: false,

                    barPercentage: 0.5,

                    categoryPercentage: 0.7

                }

            ]

        },

        options: {

            responsive: true,

            plugins: {

                legend: {
                    display: false
                },

                tooltip: tip

            },

            scales: scalesBase

        }

    }
);


function updateVPM(y) {

    fetch(
        'ajax/chart-data.php?type=vpm&year=' + y
    )

    .then(r => r.json())

    .then(d => {

        vpmChart.data.datasets[0].data = d;

        vpmChart.update();

    });

}


/* =========================================================
   MINOR VS MAJOR
========================================================= */

let mvmChart = new Chart(
    document.getElementById('mvmChart'),
    {

        type: 'line',

        data: {

            labels: months,

            datasets: [

                {

                    label: 'Minor',

                    data: <?= json_encode($minorData) ?>,

                    borderColor: '#f59e0b',

                    backgroundColor: 'rgba(245,158,11,0.07)',

                    tension: 0.4,

                    pointRadius: 0,

                    pointHoverRadius: 5,

                    pointHoverBackgroundColor: '#f59e0b',

                    pointHoverBorderColor: '#fff',

                    pointHoverBorderWidth: 2,

                    fill: true,

                    borderWidth: 2

                },

                {

                    label: 'Major',

                    data: <?= json_encode($majorData) ?>,

                    borderColor: '#ef4444',

                    backgroundColor: 'rgba(239,68,68,0.06)',

                    tension: 0.4,

                    pointRadius: 0,

                    pointHoverRadius: 5,

                    pointHoverBackgroundColor: '#ef4444',

                    pointHoverBorderColor: '#fff',

                    pointHoverBorderWidth: 2,

                    fill: true,

                    borderWidth: 2

                }

            ]

        },

        options: {

            responsive: true,

            interaction: {

                mode: 'index',

                intersect: false

            },

            plugins: {

                legend: {
                    display: false
                },

                tooltip: tip

            },

            scales: scalesBase

        }

    }
);


function updateMVM(y) {

    fetch(
        'ajax/chart-data.php?type=mvm&year=' + y
    )

    .then(r => r.json())

    .then(d => {

        mvmChart.data.datasets[0].data = d.minor;

        mvmChart.data.datasets[1].data = d.major;

        mvmChart.update();

    });

}


/* =========================================================
   DEPARTMENT
========================================================= */

let deptChart = new Chart(
    document.getElementById('deptChart'),
    {

        type: 'bar',

        data: {

            labels: <?= json_encode($deptLabels) ?>,

            datasets: [

                {

                    label: 'Violations',

                    data: <?= json_encode($deptData) ?>,

                    backgroundColor:
                        <?= json_encode(
                            array_map(
                                fn($c) => $c . '22',
                                $deptColors
                            )
                        ) ?>,

                    borderColor:
                        <?= json_encode($deptColors) ?>,

                    borderWidth: 1.5,

                    borderRadius: 5,

                    barPercentage: 0.55

                }

            ]

        },

        options: {

            indexAxis: 'y',

            responsive: true,

            plugins: {

                legend: {
                    display: false
                },

                tooltip: tip

            },

            scales: {

                x: {

                    beginAtZero: true,

                    grid: {
                        color: G
                    },

                    border: {
                        display: false
                    },

                    ticks: {
                        padding: 6,
                        maxTicksLimit: 5
                    }

                },

                y: {

                    grid: {
                        display: false
                    },

                    border: {
                        display: false
                    },

                    ticks: {
                        padding: 8
                    }

                }

            }

        }

    }
);


function updateDept(y) {

    fetch(
        'ajax/chart-data.php?type=dept&year=' + y
    )

    .then(r => r.json())

    .then(d => {

        deptChart.data.labels = d.labels;

        deptChart.data.datasets[0].data = d.data;

        deptChart.data.datasets[0].backgroundColor =
            d.colors.map(c => c + '22');

        deptChart.data.datasets[0].borderColor =
            d.colors;

        deptChart.update();

    });

}


/* =========================================================
   COURSE
========================================================= */

let courseChart = new Chart(
    document.getElementById('courseChart'),
    {

        type: 'bar',

        data: {

            labels: <?= json_encode($courseLabels) ?>,

            datasets: [

                {

                    label: 'Violations',

                    data: <?= json_encode($courseData) ?>,

                    backgroundColor:
                        <?= json_encode(
                            array_map(
                                fn($c) => $c . '28',
                                $courseColors
                            )
                        ) ?>,

                    borderColor:
                        <?= json_encode($courseColors) ?>,

                    borderWidth: 1.5,

                    borderRadius: {

                        topLeft: 4,

                        topRight: 4,

                        bottomLeft: 0,

                        bottomRight: 0

                    },

                    borderSkipped: false,

                    barPercentage: 0.5

                }

            ]

        },

        options: {

            responsive: true,

            plugins: {

                legend: {
                    display: false
                },

                tooltip: tip

            },

            scales: scalesBase

        }

    }
);


function updateCourse(y) {

    fetch(
        'ajax/chart-data.php?type=course&year=' + y
    )

    .then(r => r.json())

    .then(d => {

        courseChart.data.labels = d.labels;

        courseChart.data.datasets[0].data = d.data;

        courseChart.data.datasets[0].backgroundColor =
            d.colors.map(c => c + '28');

        courseChart.data.datasets[0].borderColor =
            d.colors;

        courseChart.update();

    });

}


/* =========================================================
   SPECIFIC VIOLATION
========================================================= */

let svChart = new Chart(
    document.getElementById('svChart'),
    {

        type: 'line',

        data: {

            labels: months,

            datasets: [

                {

                    label: 'School of Technology',

                    data: Array(12).fill(0),

                    borderColor: '#e05454',

                    backgroundColor:
                        'rgba(224,84,84,0.06)',

                    tension: 0.4,

                    fill: true,

                    borderWidth: 2,

                    pointRadius: 0,

                    pointHoverRadius: 5,

                    pointHoverBackgroundColor:
                        '#e05454',

                    pointHoverBorderColor: '#fff',

                    pointHoverBorderWidth: 2

                },

                {

                    label: 'School of Education',

                    data: Array(12).fill(0),

                    borderColor: '#4f8ef7',

                    backgroundColor:
                        'rgba(79,142,247,0.06)',

                    tension: 0.4,

                    fill: true,

                    borderWidth: 2,

                    pointRadius: 0,

                    pointHoverRadius: 5,

                    pointHoverBackgroundColor:
                        '#4f8ef7',

                    pointHoverBorderColor: '#fff',

                    pointHoverBorderWidth: 2

                },

                {

                    label: 'School of Business',

                    data: Array(12).fill(0),

                    borderColor: '#f4973a',

                    backgroundColor:
                        'rgba(244,151,58,0.05)',

                    tension: 0.4,

                    fill: true,

                    borderWidth: 2,

                    pointRadius: 0,

                    pointHoverRadius: 5,

                    pointHoverBackgroundColor:
                        '#f4973a',

                    pointHoverBorderColor: '#fff',

                    pointHoverBorderWidth: 2

                }

            ]

        },

        options: {

            responsive: true,

            interaction: {

                mode: 'index',

                intersect: false

            },

            plugins: {

                legend: {
                    display: false
                },

                tooltip: tip

            },

            scales: scalesBase

        }

    }
);


function updateSV() {

    const y =
        document.getElementById('svYear').value;

    const v =
        document.getElementById('svViolation').value;


    fetch(
        'ajax/chart-data.php?type=sv&year=' +
        y +
        '&violation=' +
        encodeURIComponent(v)
    )

    .then(r => r.json())

    .then(d => {

        svChart.data.datasets[0].data = d.sot;

        svChart.data.datasets[1].data = d.soe;

        svChart.data.datasets[2].data = d.sob;

        svChart.update();

    });

}


updateSV();


/* =========================================================
   NOTIFICATIONS
========================================================= */

function escHtml(s) {

    return String(s)

        .replace(/&/g, '&amp;')

        .replace(/</g, '&lt;')

        .replace(/>/g, '&gt;')

        .replace(/"/g, '&quot;');

}


function fmtDate(s) {

    const d = new Date(s);

    return d.toLocaleDateString(
        'en-US',
        {
            month: 'short',
            day: 'numeric',
            year: 'numeric'
        }
    ) + ' · ' +

    d.toLocaleTimeString(
        'en-US',
        {
            hour: '2-digit',
            minute: '2-digit'
        }
    );

}


function renderNotifs(data) {

    const badge =
        document.getElementById('notifBadge');

    const pill =
        document.getElementById('notifPill');

    const listEl =
        document.getElementById('notifList');

    const bellBtn =
        document.getElementById('bellBtn');


    const isOpen =
        bellBtn
            ?.closest('.dropdown')
            ?.classList.contains('show');


    if (data.unread > 0) {

        if (badge) {

            badge.textContent = data.unread;

            badge.style.display = '';

        } else {

            const nb =
                document.createElement('span');

            nb.id = 'notifBadge';

            nb.className = 'notif-badge';

            nb.textContent = data.unread;

            bellBtn?.appendChild(nb);

        }


        if (pill) {

            pill.textContent =
                data.unread + ' new';

            pill.classList.add('visible');

        }

    } else {

        if (badge) {
            badge.style.display = 'none';
        }

        if (pill) {
            pill.classList.remove('visible');
        }

    }


    if (!listEl || isOpen) {
        return;
    }


    if (!data.notifications.length) {

        listEl.innerHTML = `
            <div class="notif-empty">
                <i class="fas fa-bell-slash"></i>
                <p>You're all caught up</p>
            </div>
        `;

        return;

    }


    listEl.innerHTML =
        data.notifications.map(n => `

            <a
                class="notif-item${n.is_read == 0 ? ' unread' : ''}"
                href="${escHtml(n.link || '#')}"
                onclick="markRead(${n.id},this);return true;"
            >

                <div class="notif-avatar">
                    <i class="fas fa-exclamation"></i>
                </div>


                <div class="notif-content">

                    <div class="notif-text">
                        ${escHtml(n.message)}
                    </div>


                    <div class="notif-ts">

                        <i
                            class="fas fa-clock"
                            style="margin-right:3px;font-size:9px;"
                        ></i>

                        ${fmtDate(n.created_at)}

                    </div>

                </div>


                ${
                    n.is_read == 0
                        ? '<div class="notif-dot"></div>'
                        : ''
                }

            </a>

        `).join('');

}


/* =========================================================
   MARK NOTIFICATION AS READ
========================================================= */

function markRead(id, el) {

    fetch(
        'ajax/notifications.php?action=read&id=' + id
    );


    el.classList.remove('unread');

    el.querySelector(
        '.notif-dot'
    )?.remove();


    const badge =
        document.getElementById('notifBadge');

    const pill =
        document.getElementById('notifPill');


    if (badge) {

        const n =
            parseInt(badge.textContent) - 1;

        if (n <= 0) {

            badge.style.display = 'none';

        } else {

            badge.textContent = n;

        }

    }


    if (pill) {

        const n =
            parseInt(pill.textContent) - 1;

        if (n <= 0) {

            pill.classList.remove('visible');

        } else {

            pill.textContent =
                n + ' new';

        }

    }

}


/* =========================================================
   MARK ALL NOTIFICATIONS AS READ
========================================================= */

function markAllRead() {

    fetch(
        'ajax/notifications.php?action=read_all'
    );


    document
        .querySelectorAll(
            '#notifList .notif-item'
        )
        .forEach(el => {

            el.classList.remove('unread');

            el.querySelector(
                '.notif-dot'
            )?.remove();

        });


    const badge =
        document.getElementById('notifBadge');

    if (badge) {
        badge.style.display = 'none';
    }


    document
        .getElementById('notifPill')
        ?.classList.remove('visible');

}


/* =========================================================
   NOTIFICATION POLLING
========================================================= */

document.addEventListener(
    'DOMContentLoaded',
    () => {

        fetch(
            'ajax/notifications.php?action=poll'
        )

        .then(r => r.ok ? r.json() : null)

        .then(d => {

            if (d && !d.error) {
                renderNotifs(d);
            }

        });


        setInterval(
            () => {

                fetch(
                    'ajax/notifications.php?action=poll'
                )

                .then(
                    r => r.ok ? r.json() : null
                )

                .then(d => {

                    if (d && !d.error) {
                        renderNotifs(d);
                    }

                });

            },
            15000
        );

    }
);

</script>

</body>

</html>