<?php
// Enable error reporting for debugging
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Start session with consistent settings
ini_set('session.gc_maxlifetime', 3600);
session_set_cookie_params(3600, '/');
session_start();

// Helper function to safely output HTML
function safe_html($value, $default = '') {
    return htmlspecialchars($value ?? $default, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

// Log session data for debugging
error_log("Session data in admin/manage_matches.php: " . print_r($_SESSION, true));

// Verify admin authentication
if (!isset($_SESSION['user_id']) || empty($_SESSION['user_id']) || ($_SESSION['role'] ?? 'membre') !== 'admin') {
    error_log("Unauthorized access attempt to admin/manage_matches.php");
    header('Location: ../index.php');
    exit();
}

// Database connection
require_once '../includes/db-config.php';

// Verify database connection
if (!isset($pdo) || $pdo === null) {
    error_log("Critical error: Database connection not established in admin/manage_matches.php");
    die("A database connection error occurred. Please contact the administrator.");
}

// Fetch all teams for form dropdowns
try {
    $teams = $pdo->query("SELECT id, team_name AS name FROM teams ORDER BY team_name")->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Error fetching teams: " . $e->getMessage());
    die("Erreur lors du chargement des équipes.");
}

// Fetch all poules (groups) for form dropdowns
try {
    $poules = $pdo->query("SELECT id, name, competition FROM poules ORDER BY competition, name")->fetchAll(PDO::FETCH_ASSOC);
    
    // If poules table doesn't exist yet, create it
    if (empty($poules)) {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS poules (
                id INT AUTO_INCREMENT PRIMARY KEY,
                name VARCHAR(100) NOT NULL,
                competition VARCHAR(100) NOT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            )
        ");
        
        // Add poule_id column to matches table if it doesn't exist
        try {
            $columnExists = $pdo->query("SHOW COLUMNS FROM matches LIKE 'poule_id'")->rowCount() > 0;
            if (!$columnExists) {
                $pdo->exec("ALTER TABLE matches ADD COLUMN poule_id INT NULL");
            }
        } catch (PDOException $e) {
            error_log("Error checking or adding poule_id column: " . $e->getMessage());
        }
    }
} catch (PDOException $e) {
    error_log("Error fetching poules: " . $e->getMessage());
    // Create poules table if it doesn't exist
    try {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS poules (
                id INT AUTO_INCREMENT PRIMARY KEY,
                name VARCHAR(100) NOT NULL,
                competition VARCHAR(100) NOT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            )
        ");
        // Add poule_id column to matches table if it doesn't exist
        $pdo->exec("ALTER TABLE matches ADD COLUMN poule_id INT NULL");
        $poules = [];
    } catch (PDOException $e2) {
        error_log("Error creating poules table: " . $e2->getMessage());
        die("Erreur lors de la création de la table des poules.");
    }
}

// Handle poule form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_poule'])) {
    try {
        $poule_name = filter_input(INPUT_POST, 'poule_name', FILTER_SANITIZE_STRING);
        $poule_competition = filter_input(INPUT_POST, 'poule_competition', FILTER_SANITIZE_STRING);
        
        if (!$poule_name || !$poule_competition) {
            $_SESSION['error'] = "Le nom de la poule et la compétition sont requis.";
        } else {
            $stmt = $pdo->prepare("INSERT INTO poules (name, competition) VALUES (?, ?)");
            $stmt->execute([$poule_name, $poule_competition]);
            $_SESSION['message'] = "Poule ajoutée avec succès !";
        }
        
        header("Location: manage_matches.php");
        exit();
    } catch (PDOException $e) {
        error_log("Error adding poule: " . $e->getMessage());
        $_SESSION['error'] = "Erreur lors de l'ajout de la poule.";
        header("Location: manage_matches.php");
        exit();
    }
}

// Handle match form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        // Add or update match
        if (isset($_POST['add_match']) || isset($_POST['update_match'])) {
            $match_id = isset($_POST['match_id']) ? filter_input(INPUT_POST, 'match_id', FILTER_VALIDATE_INT) : null;
            $team_home = filter_input(INPUT_POST, 'team_home', FILTER_SANITIZE_FULL_SPECIAL_CHARS);
            $team_away = filter_input(INPUT_POST, 'team_away', FILTER_SANITIZE_FULL_SPECIAL_CHARS);
            $competition = filter_input(INPUT_POST, 'competition', FILTER_SANITIZE_FULL_SPECIAL_CHARS);
            $poule_id = filter_input(INPUT_POST, 'poule_id', FILTER_VALIDATE_INT);
            $match_date = filter_input(INPUT_POST, 'match_date', FILTER_SANITIZE_FULL_SPECIAL_CHARS);
            $match_time = filter_input(INPUT_POST, 'match_time', FILTER_SANITIZE_FULL_SPECIAL_CHARS);
            $phase = filter_input(INPUT_POST, 'phase', FILTER_SANITIZE_FULL_SPECIAL_CHARS);
            $venue = filter_input(INPUT_POST, 'venue', FILTER_SANITIZE_FULL_SPECIAL_CHARS);
            $score_home = filter_input(INPUT_POST, 'score_home', FILTER_VALIDATE_INT, ['options' => ['default' => null]]);
            $score_away = filter_input(INPUT_POST, 'score_away', FILTER_VALIDATE_INT, ['options' => ['default' => null]]);

            // Validate required fields
            if (!$team_home || !$team_away || $team_home === $team_away || !$competition || !$match_date || !$match_time || !$phase || !$venue) {
                error_log("Invalid input for match: team_home=$team_home, team_away=$team_away, competition=$competition, date=$match_date, time=$match_time, phase=$phase, venue=$venue");
                $_SESSION['error'] = "Tous les champs obligatoires doivent être remplis et les équipes doivent être différentes.";
            } else {
                // Verify team names exist
                $stmt = $pdo->prepare("SELECT team_name FROM teams WHERE team_name IN (?, ?)");
                $stmt->execute([$team_home, $team_away]);
                $existing_teams = $stmt->fetchAll(PDO::FETCH_COLUMN);
                if (count($existing_teams) != 2) {
                    $_SESSION['error'] = "Une ou les deux équipes sélectionnées n'existent pas.";
                } else {
                    // Determine status based on scores
                    $status = ($score_home !== null && $score_away !== null) ? 'finished' : 'ongoing';

                    if (isset($_POST['add_match'])) {
                        $stmt = $pdo->prepare("
                            INSERT INTO matches (competition, phase, match_date, match_time, team_home, team_away, venue, score_home, score_away, status, poule_id)
                            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                        ");
                        $stmt->execute([$competition, $phase, $match_date, $match_time, $team_home, $team_away, $venue, $score_home, $score_away, $status, $poule_id]);
                        $_SESSION['message'] = "Match ajouté avec succès !";
                    } elseif (isset($_POST['update_match']) && $match_id) {
                        $stmt = $pdo->prepare("
                            UPDATE matches
                            SET competition = ?, phase = ?, match_date = ?, match_time = ?, team_home = ?, team_away = ?, venue = ?, score_home = ?, score_away = ?, status = ?, poule_id = ?
                            WHERE id = ?
                        ");
                        $stmt->execute([$competition, $phase, $match_date, $match_time, $team_home, $team_away, $venue, $score_home, $score_away, $status, $poule_id, $match_id]);
                        $_SESSION['message'] = "Match mis à jour avec succès !";
                    }
                }
            }
        }

        // Delete match
        if (isset($_POST['delete_match'])) {
            $match_id = filter_input(INPUT_POST, 'match_id', FILTER_VALIDATE_INT);
            if ($match_id) {
                $stmt = $pdo->prepare("DELETE FROM goals WHERE match_id = ?");
                $stmt->execute([$match_id]);
                $stmt = $pdo->prepare("DELETE FROM matches WHERE id = ?");
                $stmt->execute([$match_id]);
                $_SESSION['message'] = "Match supprimé avec succès !";
            } else {
                $_SESSION['error'] = "ID de match invalide.";
            }
        }

        // Handle poule deletion
        if (isset($_POST['delete_poule'])) {
            $poule_id = filter_input(INPUT_POST, 'poule_id', FILTER_VALIDATE_INT);
            if ($poule_id) {
                // Update matches to remove poule_id reference
                $stmt = $pdo->prepare("UPDATE matches SET poule_id = NULL WHERE poule_id = ?");
                $stmt->execute([$poule_id]);
                
                // Delete the poule
                $stmt = $pdo->prepare("DELETE FROM poules WHERE id = ?");
                $stmt->execute([$poule_id]);
                
                $_SESSION['message'] = "Poule supprimée avec succès !";
            } else {
                $_SESSION['error'] = "ID de poule invalide.";
            }
        }

        if (!isset($_SESSION['error'])) {
            header("Location: manage_matches.php");
            exit();
        }
    } catch (PDOException $e) {
        error_log("Error processing match form: " . $e->getMessage());
        $_SESSION['error'] = "Erreur lors du traitement des données.";
    }
}

// Fetch all matches with poule information
try {
    $matches = $pdo->query("
        SELECT m.id, m.team_home, m.team_away, m.match_date, m.match_time, m.phase, m.venue, m.status, 
               m.competition, m.score_home, m.score_away, m.poule_id, p.name as poule_name
        FROM matches m
        LEFT JOIN poules p ON m.poule_id = p.id
        ORDER BY m.match_date DESC
    ")->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Error fetching matches: " . $e->getMessage());
    die("Erreur lors du chargement des matchs.");
}

// Reload poules after any changes
try {
    $poules = $pdo->query("SELECT id, name, competition FROM poules ORDER BY competition, name")->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Error reloading poules: " . $e->getMessage());
    $poules = [];
}
?>

<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gestion des Matchs - UJEM</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="../styles.css">
    <style>
        .sidebar {
            min-height: 100vh;
            background: #343a40;
        }
        .sidebar .nav-link {
            color: rgba(255, 255, 255, 0.75);
        }
        .sidebar .nav-link:hover, .sidebar .nav-link.active {
            color: white;
            background: rgba(255, 255, 255, 0.1);
        }
        .nav-tabs .nav-link {
            color: #495057;
        }
        .nav-tabs .nav-link.active {
            font-weight: bold;
        }
    </style>
</head>
<body>
    <div class="container-fluid">
        <div class="row">
            <!-- Sidebar -->
  <?php include '../includes/sidebar.php'; ?>   

            <!-- Main Content -->
            <main class="col-md-9 ms-sm-auto col-lg-10 px-md-4 py-4">
                <h2 class="h3 mb-4"><i class="fas fa-calendar-alt me-2"></i>Gestion des Matchs</h2>

                <?php if (isset($_SESSION['message'])): ?>
                    <div class="alert alert-success alert-dismissible fade show" role="alert">
                        <?= safe_html($_SESSION['message']) ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                    </div>
                    <?php unset($_SESSION['message']); ?>
                <?php endif; ?>

                <?php if (isset($_SESSION['error'])): ?>
                    <div class="alert alert-danger alert-dismissible fade show" role="alert">
                        <?= safe_html($_SESSION['error']) ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                    </div>
                    <?php unset($_SESSION['error']); ?>
                <?php endif; ?>

                <!-- Tabs navigation -->
                <ul class="nav nav-tabs mb-4" id="manageTabs" role="tablist">
                    <li class="nav-item" role="presentation">
                        <button class="nav-link active" id="matches-tab" data-bs-toggle="tab" data-bs-target="#matches" type="button" role="tab" aria-controls="matches" aria-selected="true">
                            <i class="fas fa-calendar-alt me-2"></i>Matchs
                        </button>
                    </li>
                    <li class="nav-item" role="presentation">
                        <button class="nav-link" id="poules-tab" data-bs-toggle="tab" data-bs-target="#poules" type="button" role="tab" aria-controls="poules" aria-selected="false">
                            <i class="fas fa-layer-group me-2"></i>Poules
                        </button>
                    </li>
                </ul>

                <!-- Tab content -->
                <div class="tab-content" id="manageTabsContent">
                    <!-- Matches Tab -->
                    <div class="tab-pane fade show active" id="matches" role="tabpanel" aria-labelledby="matches-tab">
                        <!-- Form to add/edit match -->
                        <div class="card mb-4">
                            <div class="card-header bg-primary text-white">
                                <h5 class="mb-0"><i class="fas fa-plus me-2"></i>Ajouter/Modifier un Match</h5>
                            </div>
                            <div class="card-body">
                                <form method="post">
                                    <input type="hidden" name="match_id" id="match_id">
                                    <div class="row g-3">
                                        <div class="col-md-4">
                                            <label for="competition" class="form-label">Compétition</label>
                                            <input type="text" name="competition" id="competition" class="form-control" placeholder="Ex: coupe UJEM" required>
                                        </div>
                                        <div class="col-md-4">
                                            <label for="phase" class="form-label">Phase</label>
                                            <input type="text" name="phase" id="phase" class="form-control" placeholder="Ex: Phase de groupe" required>
                                        </div>
                                        <div class="col-md-4">
                                            <label for="poule_id" class="form-label">Poule</label>
                                            <select name="poule_id" id="poule_id" class="form-select">
                                                <option value="">-- Sélectionner une poule --</option>
                                                <?php foreach ($poules as $poule): ?>
                                                    <option value="<?= safe_html($poule['id']) ?>">
                                                        <?= safe_html(($poule['competition'] ?? '') . ' - ' . ($poule['name'] ?? '')) ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                        <div class="col-md-6">
                                            <label for="team_home" class="form-label">Équipe Domicile</label>
                                            <select name="team_home" id="team_home" class="form-select" required>
                                                <option value="">Sélectionner une équipe</option>
                                                <?php foreach ($teams as $team): ?>
                                                    <option value="<?= safe_html($team['name']) ?>"><?= safe_html($team['name']) ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                        <div class="col-md-6">
                                            <label for="team_away" class="form-label">Équipe Extérieure</label>
                                            <select name="team_away" id="team_away" class="form-select" required>
                                                <option value="">Sélectionner une équipe</option>
                                                <?php foreach ($teams as $team): ?>
                                                    <option value="<?= safe_html($team['name']) ?>"><?= safe_html($team['name']) ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                        <div class="col-md-6">
                                            <label for="match_date" class="form-label">Date du Match</label>
                                            <input type="date" name="match_date" id="match_date" class="form-control" required>
                                        </div>
                                        <div class="col-md-6">
                                            <label for="match_time" class="form-label">Heure du Match</label>
                                            <input type="time" name="match_time" id="match_time" class="form-control" required>
                                        </div>
                                        <div class="col-md-6">
                                            <label for="venue" class="form-label">Lieu</label>
                                            <input type="text" name="venue" id="venue" class="form-control" placeholder="Ex: champrou de melekoukro" required>
                                        </div>
                                        <div class="col-md-3">
                                            <label for="score_home" class="form-label">Score Domicile</label>
                                            <input type="number" name="score_home" id="score_home" class="form-control" min="0" placeholder="Ex: 2">
                                        </div>
                                        <div class="col-md-3">
                                            <label for="score_away" class="form-label">Score Extérieur</label>
                                            <input type="number" name="score_away" id="score_away" class="form-control" min="0" placeholder="Ex: 1">
                                        </div>
                                    </div>
                                    <div class="d-grid gap-2 d-md-flex justify-content-md-end mt-3">
                                        <button type="submit" name="add_match" id="add_match" class="btn btn-primary">Ajouter</button>
                                        <button type="submit" name="update_match" id="update_match" class="btn btn-primary d-none">Mettre à jour</button>
                                        <button type="button" id="reset_form" class="btn btn-secondary d-none">Annuler</button>
                                    </div>
                                </form>
                            </div>
                        </div>

                        <!-- Matches list -->
                        <div class="card">
                            <div class="card-header bg-primary text-white">
                                <h5 class="mb-0"><i class="fas fa-list me-2"></i>Liste des Matchs</h5>
                            </div>
                            <div class="card-body">
                                <?php if (empty($matches)): ?>
                                    <div class="alert alert-info">Aucun match enregistré.</div>
                                <?php else: ?>
                                    <!-- Filter matches by poule -->
                                    <div class="row mb-3">
                                        <div class="col-md-6">
                                            <label for="filter_poule" class="form-label">Filtrer par poule:</label>
                                            <select id="filter_poule" class="form-select">
                                                <option value="">Tous les matchs</option>
                                                <?php foreach ($poules as $poule): ?>
                                                    <option value="<?= safe_html($poule['id']) ?>">
                                                        <?= safe_html(($poule['competition'] ?? '') . ' - ' . ($poule['name'] ?? '')) ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                    </div>
                                    
                                    <div class="table-responsive">
                                        <table class="table table-hover" id="matches_table">
                                            <thead>
                                                <tr>
                                                    <th>Date</th>
                                                    <th>Compétition</th>
                                                    <th>Match</th>
                                                    <th>Score</th>
                                                    <th>Phase</th>
                                                    <th>Poule</th>
                                                    <th>Lieu</th>
                                                    <th>Statut</th>
                                                    <th>Actions</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php foreach ($matches as $match): ?>
                                                <tr data-poule-id="<?= safe_html($match['poule_id'] ?? '') ?>">
                                                    <td>
                                                        <?= !empty($match['match_date']) ? date('d/m/Y H:i', strtotime($match['match_date'] . ' ' . ($match['match_time'] ?? '00:00'))) : 'Date non définie' ?>
                                                    </td>
                                                    <td><?= safe_html($match['competition']) ?></td>
                                                    <td><?= safe_html($match['team_home']) ?> vs <?= safe_html($match['team_away']) ?></td>
                                                    <td>
                                                        <?php if (isset($match['score_home']) && isset($match['score_away'])): ?>
                                                            <?= safe_html($match['score_home']) ?> - <?= safe_html($match['score_away']) ?>
                                                        <?php else: ?>
                                                            -
                                                        <?php endif; ?>
                                                    </td>
                                                    <td><?= safe_html($match['phase']) ?></td>
                                                    <td><?= safe_html($match['poule_name'] ?? 'Non assigné') ?></td>
                                                    <td><?= safe_html($match['venue']) ?></td>
                                                    <td><?= ($match['status'] ?? '') === 'ongoing' ? 'En cours' : 'Terminé' ?></td>
                                                    <td>
                                                        <button class="btn btn-sm btn-outline-primary edit-match" 
                                                                data-id="<?= safe_html($match['id']) ?>" 
                                                                data-team-home="<?= safe_html($match['team_home']) ?>" 
                                                                data-team-away="<?= safe_html($match['team_away']) ?>" 
                                                                data-competition="<?= safe_html($match['competition']) ?>" 
                                                                data-date="<?= safe_html($match['match_date']) ?>" 
                                                                data-time="<?= safe_html($match['match_time']) ?>" 
                                                                data-phase="<?= safe_html($match['phase']) ?>" 
                                                                data-venue="<?= safe_html($match['venue']) ?>" 
                                                                data-score-home="<?= safe_html($match['score_home']) ?>" 
                                                                data-score-away="<?= safe_html($match['score_away']) ?>"
                                                                data-poule-id="<?= safe_html($match['poule_id'] ?? '') ?>">
                                                            <i class="fas fa-edit"></i> Modifier
                                                        </button>
                                                        <form method="post" class="d-inline" onsubmit="return confirm('Voulez-vous vraiment supprimer ce match ?');">
                                                            <input type="hidden" name="match_id" value="<?= safe_html($match['id']) ?>">
                                                            <button type="submit" name="delete_match" class="btn btn-sm btn-outline-danger">
                                                                <i class="fas fa-trash"></i> Supprimer
                                                            </button>
                                                        </form>
                                                    </td>
                                                </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                    <!-- Poules Tab -->
                    <div class="tab-pane fade" id="poules" role="tabpanel" aria-labelledby="poules-tab">
                        <!-- Form to add poule -->
                        <div class="card mb-4">
                            <div class="card-header bg-primary text-white">
                                <h5 class="mb-0"><i class="fas fa-plus me-2"></i>Ajouter une Poule</h5>
                            </div>
                            <div class="card-body">
                                <form method="post">
                                    <div class="row g-3">
                                        <div class="col-md-6">
                                            <label for="poule_name" class="form-label">Nom de la Poule</label>
                                            <input type="text" name="poule_name" id="poule_name" class="form-control" placeholder="Ex: Poule A" required>
                                        </div>
                                        <div class="col-md-6">
                                            <label for="poule_competition" class="form-label">Compétition</label>
                                            <input type="text" name="poule_competition" id="poule_competition" class="form-control" placeholder="Ex: Coupe UJEM 2025" required>
                                        </div>
                                    </div>
                                    <div class="d-grid gap-2 d-md-flex justify-content-md-end mt-3">
                                        <button type="submit" name="add_poule" class="btn btn-primary">Ajouter la Poule</button>
                                    </div>
                                </form>
                            </div>
                        </div>

                        <!-- Poules list -->
                        <div class="card">
                            <div class="card-header bg-primary text-white">
                                <h5 class="mb-0"><i class="fas fa-layer-group me-2"></i>Liste des Poules</h5>
                            </div>
                            <div class="card-body">
                                <?php if (empty($poules)): ?>
                                    <div class="alert alert-info">Aucune poule enregistrée.</div>
                                <?php else: ?>
                                    <div class="table-responsive">
                                        <table class="table table-hover">
                                            <thead>
                                                <tr>
                                                    <th>Nom</th>
                                                    <th>Compétition</th>
                                                    <th>Nombre de Matchs</th>
                                                    <th>Actions</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php foreach ($poules as $poule): 
                                                    // Count matches in this poule
                                                    $match_count = 0;
                                                    foreach ($matches as $match) {
                                                        if (isset($match['poule_id']) && $match['poule_id'] == $poule['id']) {
                                                            $match_count++;
                                                        }
                                                    }
                                                ?>
                                                <tr>
                                                    <td><?= safe_html($poule['name']) ?></td>
                                                    <td><?= safe_html($poule['competition']) ?></td>
                                                    <td><?= $match_count ?></td>
                                                    <td>
                                                        <form method="post" class="d-inline" onsubmit="return confirm('Voulez-vous vraiment supprimer cette poule ? Les matchs resteront mais ne seront plus associés à cette poule.');">
                                                            <input type="hidden" name="poule_id" value="<?= safe_html($poule['id']) ?>">
                                                            <button type="submit" name="delete_poule" class="btn btn-sm btn-outline-danger">
                                                                <i class="fas fa-trash"></i> Supprimer
                                                            </button>
                                                        </form>
                                                    </td>
                                                </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
            </main>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Handle edit button click
        document.querySelectorAll('.edit-match').forEach(button => {
            button.addEventListener('click', function() {
                document.getElementById('match_id').value = this.dataset.id;
                document.getElementById('team_home').value = this.dataset.teamHome;
                document.getElementById('team_away').value = this.dataset.teamAway;
                document.getElementById('competition').value = this.dataset.competition;
                document.getElementById('match_date').value = this.dataset.date;
                document.getElementById('match_time').value = this.dataset.time;
                document.getElementById('phase').value = this.dataset.phase;
                document.getElementById('venue').value = this.dataset.venue;
                document.getElementById('score_home').value = this.dataset.scoreHome || '';
                document.getElementById('score_away').value = this.dataset.scoreAway || '';
                document.getElementById('poule_id').value = this.dataset.pouleId || '';
                document.getElementById('add_match').classList.add('d-none');
                document.getElementById('update_match').classList.remove('d-none');
                document.getElementById('reset_form').classList.remove('d-none');
                
                // Scroll to form
                document.querySelector('.card-header').scrollIntoView({ behavior: 'smooth' });
            });
        });

        // Reset form button
        document.getElementById('reset_form').addEventListener('click', function() {
            document.querySelector('form').reset();
            document.getElementById('add_match').classList.remove('d-none');
            document.getElementById('update_match').classList.add('d-none');
            document.getElementById('reset_form').classList.add('d-none');
        });

        // Filter matches by poule
        document.getElementById('filter_poule').addEventListener('change', function() {
            const pouleId = this.value;
            const rows = document.querySelectorAll('#matches_table tbody tr');
            
            rows.forEach(row => {
                if (!pouleId || row.dataset.pouleId === pouleId) {
                    row.style.display = '';
                } else {
                    row.style.display = 'none';
                }
            });
        });

        // Generate standings table for each poule
        function generateStandings() {
            const poules = <?= json_encode($poules) ?>;
            const matches = <?= json_encode($matches) ?>;
            
            poules.forEach(poule => {
                // Get matches for this poule
                const pouleMatches = matches.filter(match => 
                    match.poule_id == poule.id && match.status === 'finished'
                );
                
                if (pouleMatches.length === 0) return;
                
                // Get unique teams in this poule
                const teams = new Set();
                pouleMatches.forEach(match => {
                    teams.add(match.team_home);
                    teams.add(match.team_away);
                });
                
                // Calculate stats for each team
                const teamStats = {};
                teams.forEach(team => {
                    teamStats[team] = {
                        team: team,
                        played: 0,
                        won: 0,
                        drawn: 0,
                        lost: 0,
                        goalsFor: 0,
                        goalsAgainst: 0,
                        points: 0
                    };
                });
                
                // Calculate stats from matches
                pouleMatches.forEach(match => {
                    if (match.score_home === null || match.score_away === null) return;
                    
                    const homeTeam = match.team_home;
                    const awayTeam = match.team_away;
                    const homeScore = parseInt(match.score_home);
                    const awayScore = parseInt(match.score_away);
                    
                    // Update home team stats
                    teamStats[homeTeam].played++;
                    teamStats[homeTeam].goalsFor += homeScore;
                    teamStats[homeTeam].goalsAgainst += awayScore;
                    
                    // Update away team stats
                    teamStats[awayTeam].played++;
                    teamStats[awayTeam].goalsFor += awayScore;
                    teamStats[awayTeam].goalsAgainst += homeScore;
                    
                    if (homeScore > awayScore) {
                        // Home team won
                        teamStats[homeTeam].won++;
                        teamStats[homeTeam].points += 3;
                        teamStats[awayTeam].lost++;
                    } else if (homeScore < awayScore) {
                        // Away team won
                        teamStats[awayTeam].won++;
                        teamStats[awayTeam].points += 3;
                        teamStats[homeTeam].lost++;
                    } else {
                        // Draw
                        teamStats[homeTeam].drawn++;
                        teamStats[homeTeam].points += 1;
                        teamStats[awayTeam].drawn++;
                        teamStats[awayTeam].points += 1;
                    }
                });
                
                // Convert to array and sort by points, then goal difference
                const sortedTeams = Object.values(teamStats).sort((a, b) => {
                    if (a.points !== b.points) {
                        return b.points - a.points;
                    }
                    const aDiff = a.goalsFor - a.goalsAgainst;
                    const bDiff = b.goalsFor - b.goalsAgainst;
                    if (aDiff !== bDiff) {
                        return bDiff - aDiff;
                    }
                    return b.goalsFor - a.goalsFor;
                });
                
                // Create the standings table
                const standingsContainer = document.createElement('div');
                standingsContainer.className = 'card mt-4';
                standingsContainer.dataset.poulesStandings = poule.id;
                standingsContainer.style.display = 'none'; // Hide by default
                
                const standingsHeader = document.createElement('div');
                standingsHeader.className = 'card-header bg-success text-white';
                standingsHeader.innerHTML = `<h5 class="mb-0"><i class="fas fa-chart-bar me-2"></i>Classement - ${poule.name} (${poule.competition})</h5>`;
                
                const standingsBody = document.createElement('div');
                standingsBody.className = 'card-body';
                
                const standingsTable = document.createElement('table');
                standingsTable.className = 'table table-striped table-hover';
                standingsTable.innerHTML = `
                    <thead>
                        <tr>
                            <th>Pos</th>
                            <th>Équipe</th
                            <th>J</th>
                            <th>G</th>
                            <th>N</th>
                            <th>P</th>
                            <th>BP</th>
                            <th>BC</th>
                            <th>Diff</th>
                            <th>Pts</th>
                        </tr>
                    </thead>
                    <tbody>
                        ${sortedTeams.map((team, index) => `
                            <tr>
                                <td>${index + 1}</td>
                                <td>${team.team}</td>
                                <td>${team.played}</td>
                                <td>${team.won}</td>
                                <td>${team.drawn}</td>
                                <td>${team.lost}</td>
                                <td>${team.goalsFor}</td>
                                <td>${team.goalsAgainst}</td>
                                <td>${team.goalsFor - team.goalsAgainst}</td>
                                <td><strong>${team.points}</strong></td>
                            </tr>
                        `).join('')}
                    </tbody>
                `;
                
                standingsBody.appendChild(standingsTable);
                standingsContainer.appendChild(standingsHeader);
                standingsContainer.appendChild(standingsBody);
                
                document.getElementById('poules').appendChild(standingsContainer);
            });
            
            // Create a dropdown to select which standings to display
            const selectContainer = document.createElement('div');
            selectContainer.className = 'row mb-4';
            selectContainer.innerHTML = `
                <div class="col-md-6">
                    <label for="view_standings" class="form-label">Afficher le classement:</label>
                    <select id="view_standings" class="form-select">
                        <option value="">-- Sélectionner une poule --</option>
                        ${poules.map(poule => `
                            <option value="${poule.id}">${poule.competition} - ${poule.name}</option>
                        `).join('')}
                    </select>
                </div>
            `;
            
            // Insert after the "Add Poule" card
            const addPouleCard = document.querySelector('#poules .card');
            addPouleCard.parentNode.insertBefore(selectContainer, addPouleCard.nextSibling);
            
            // Add event listener to dropdown
            document.getElementById('view_standings').addEventListener('change', function() {
                const poulesId = this.value;
                const standingsTables = document.querySelectorAll('[data-poules-standings]');
                
                standingsTables.forEach(table => {
                    if (table.dataset.poulesStandings === poulesId) {
                        table.style.display = 'block';
                    } else {
                        table.style.display = 'none';
                    }
                });
            });
        }
        
        // Call generateStandings when the page loads
        document.addEventListener('DOMContentLoaded', function() {
            // Initialize tooltips
            var tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
            tooltipTriggerList.map(function (tooltipTriggerEl) {
                return new bootstrap.Tooltip(tooltipTriggerEl);
            });
            
            // Generate standings tables
            if (document.querySelectorAll('#poules .card').length > 0) {
                generateStandings();
            }
        });
    </script>
</body>
</html>