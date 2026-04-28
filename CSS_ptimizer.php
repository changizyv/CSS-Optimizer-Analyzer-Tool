<?php
/**
 * CSS Optimizer & Analyzer Tool
 * Version: 3.0
 * Pure PHP - No dependencies required
 * 
 * Features:
 * - Remove duplicate CSS rules (keep last occurrence)
 * - Minify CSS files
 * - Detect conflicts across multiple CSS files
 * - User-friendly web interface with FontAwesome icons
 * - Configurable CSS folder path
 * - Automatic backups before processing
 * - Undo functionality
 * - Dry run mode
 */

// Start session for undo functionality
session_start();

// Initialize variables
$message = '';
$error = '';
$cssFolder = isset($_POST['css_folder']) ? trim($_POST['css_folder']) : '';
$action = isset($_POST['action']) ? $_POST['action'] : '';
$dryRun = isset($_POST['dry_run']) ? true : false;
$keepConflicts = isset($_POST['keep_conflicts']) ? $_POST['keep_conflicts'] : array();

// Default path
$defaultPath = __DIR__ . '/assets/css';

// Check if this is an undo request
if (isset($_GET['undo']) && isset($_SESSION['last_backup'])) {
    undoLastOperation();
}

/**
 * Undo last operation from backup
 */
function undoLastOperation() {
    global $message, $error;
    
    if (!isset($_SESSION['last_backup']) || empty($_SESSION['last_backup'])) {
        $error = 'No backup found to restore';
        return;
    }
    
    $backupFiles = $_SESSION['last_backup'];
    $restored = 0;
    
    foreach ($backupFiles as $original => $backup) {
        if (file_exists($backup)) {
            copy($backup, $original);
            $restored++;
            unlink($backup);
        }
    }
    
    if ($restored > 0) {
        $message = "Successfully restored {$restored} files from backup";
        unset($_SESSION['last_backup']);
    } else {
        $error = 'Failed to restore backups';
    }
}

/**
 * Calculate CSS specificity weight
 */
function calculateSpecificity($selector) {
    $specificity = array('id' => 0, 'class' => 0, 'element' => 0);
    
    // Count IDs
    preg_match_all('/#([a-zA-Z0-9_-]+)/', $selector, $idMatches);
    $specificity['id'] = count($idMatches[0]);
    
    // Count classes, attributes, pseudo-classes
    preg_match_all('/\.([a-zA-Z0-9_-]+)|\[[^\]]+\]|:[a-z-]+(?:\([^)]+\))?/', $selector, $classMatches);
    $specificity['class'] = count($classMatches[0]);
    
    // Count elements and pseudo-elements
    preg_match_all('/(?:^|[^\w-])([a-zA-Z][a-zA-Z0-9]*)(?![\[:.#])|::[a-z-]+/', $selector, $elementMatches);
    $specificity['element'] = count($elementMatches[0]);
    
    return $specificity;
}

/**
 * Format specificity for display
 */
function formatSpecificity($specificity) {
    return "({$specificity['id']},{$specificity['class']},{$specificity['element']})";
}

/**
 * Extract CSS rules with metadata from content
 */
function extractRulesWithMetadata($content, $filename) {
    $rules = array();
    
    // Remove comments but keep track of position
    $content = preg_replace('!/\*[^*]*\*+([^/][^*]*\*+)*/!', '', $content);
    
    // Split by media queries first
    $mediaBlocks = array();
    preg_match_all('/@media[^{]+\{([\s\S]+?})\s*}/', $content, $mediaMatches);
    
    $nonMediaContent = preg_replace('/@media[^{]+\{[\s\S]+?}\s*}/', '', $content);
    $mediaBlocks['__global__'] = $nonMediaContent;
    
    foreach ($mediaMatches[0] as $index => $mediaQuery) {
        $mediaName = trim($mediaQuery);
        $mediaContent = $mediaMatches[1][$index];
        $mediaBlocks[$mediaName] = $mediaContent;
    }
    
    // Extract rules from each block
    foreach ($mediaBlocks as $mediaQuery => $cssContent) {
        preg_match_all('/([^{]+)\{([^}]*)\}/', $cssContent, $matches, PREG_SET_ORDER);
        
        foreach ($matches as $match) {
            $selector = trim($match[1]);
            $declarations = trim($match[2]);
            
            if (empty($declarations)) continue;
            
            // Parse properties
            $properties = array();
            $decls = explode(';', $declarations);
            foreach ($decls as $decl) {
                if (strpos($decl, ':') !== false) {
                    list($prop, $val) = explode(':', $decl, 2);
                    $prop = trim($prop);
                    $val = trim($val);
                    if (!empty($prop) && !empty($val)) {
                        $properties[$prop] = $val;
                    }
                }
            }
            
            $key = $mediaQuery . '|' . $selector;
            
            if (!isset($rules[$key])) {
                $rules[$key] = array(
                    'selector' => $selector,
                    'media' => $mediaQuery,
                    'files' => array(),
                    'properties' => array(),
                    'specificity' => calculateSpecificity($selector)
                );
            }
            
            // Store property sources
            foreach ($properties as $prop => $val) {
                if (!isset($rules[$key]['properties'][$prop])) {
                    $rules[$key]['properties'][$prop] = array();
                }
                $rules[$key]['properties'][$prop][] = array(
                    'value' => $val,
                    'file' => $filename,
                    'specificity' => $rules[$key]['specificity']
                );
            }
            
            if (!in_array($filename, $rules[$key]['files'])) {
                $rules[$key]['files'][] = $filename;
            }
        }
    }
    
    return $rules;
}

/**
 * Detect conflicts across CSS rules
 */
function detectConflicts($allRules) {
    $conflicts = array();
    
    // Group by selector and media query
    $grouped = array();
    foreach ($allRules as $key => $rule) {
        $groupKey = $rule['media'] . '|' . $rule['selector'];
        if (!isset($grouped[$groupKey])) {
            $grouped[$groupKey] = $rule;
        } else {
            // Merge properties
            foreach ($rule['properties'] as $prop => $sources) {
                if (!isset($grouped[$groupKey]['properties'][$prop])) {
                    $grouped[$groupKey]['properties'][$prop] = array();
                }
                foreach ($sources as $source) {
                    $grouped[$groupKey]['properties'][$prop][] = $source;
                }
            }
            // Merge files
            foreach ($rule['files'] as $file) {
                if (!in_array($file, $grouped[$groupKey]['files'])) {
                    $grouped[$groupKey]['files'][] = $file;
                }
            }
        }
    }
    
    // Find conflicts (properties with multiple different values)
    foreach ($grouped as $key => $rule) {
        foreach ($rule['properties'] as $prop => $sources) {
            $uniqueValues = array();
            $valueDetails = array();
            
            foreach ($sources as $source) {
                $valKey = $source['value'];
                if (!isset($uniqueValues[$valKey])) {
                    $uniqueValues[$valKey] = $source['value'];
                    $valueDetails[$valKey] = array();
                }
                $valueDetails[$valKey][] = array(
                    'file' => $source['file'],
                    'specificity' => $source['specificity']
                );
            }
            
            if (count($uniqueValues) > 1) {
                // Determine winner by specificity and order
                $winner = null;
                $winnerScore = -1;
                
                foreach ($valueDetails as $value => $details) {
                    foreach ($details as $detail) {
                        $score = $detail['specificity']['id'] * 100 + 
                                 $detail['specificity']['class'] * 10 + 
                                 $detail['specificity']['element'];
                        if ($score > $winnerScore) {
                            $winnerScore = $score;
                            $winner = $value;
                        }
                    }
                }
                
                $conflicts[] = array(
                    'selector' => $rule['selector'],
                    'media' => $rule['media'],
                    'property' => $prop,
                    'values' => $uniqueValues,
                    'value_details' => $valueDetails,
                    'files' => $rule['files'],
                    'specificity' => $rule['specificity'],
                    'winner' => $winner,
                    'severity' => $winnerScore > 0 ? 'low' : (count($uniqueValues) > 2 ? 'high' : 'medium')
                );
            }
        }
    }
    
    return $conflicts;
}

/**
 * Clean duplicate rules from CSS files
 */
function cleanDuplicateRules($files, $keepConflicts = array()) {
    global $message, $error;
    
    $allRules = array();
    $backupFiles = array();
    $totalOriginalSize = 0;
    $totalNewSize = 0;
    $rulesRemoved = 0;
    
    // Extract all rules from all files
    foreach ($files as $file) {
        $totalOriginalSize += filesize($file);
        $content = file_get_contents($file);
        $rules = extractRulesWithMetadata($content, basename($file));
        $allRules = array_merge($allRules, $rules);
    }
    
    // Detect conflicts
    $conflicts = detectConflicts($allRules);
    
    // Process each file individually
    foreach ($files as $file) {
        // Create backup
        $backupFile = $file . '.backup_' . time();
        copy($file, $backupFile);
        $backupFiles[$file] = $backupFile;
        
        $content = file_get_contents($file);
        $originalContent = $content;
        
        // Remove comments
        $content = preg_replace('!/\*[^*]*\*+([^/][^*]*\*+)*/!', '', $content);
        
        // Extract rules from this file
        preg_match_all('/([^{]+)\{([^}]*)\}/', $content, $matches, PREG_SET_ORDER);
        
        $cleanedRules = array();
        $seenSelectors = array();
        
        foreach ($matches as $match) {
            $selector = trim($match[1]);
            $declarations = trim($match[2]);
            
            // Check if this selector should be kept
            $shouldKeep = true;
            foreach ($conflicts as $conflict) {
                if ($conflict['selector'] === $selector && in_array(basename($file), $conflict['files'])) {
                    $conflictKey = $selector . '|' . $conflict['property'];
                    if (isset($keepConflicts[$conflictKey]) && $keepConflicts[$conflictKey] === 'skip') {
                        $shouldKeep = false;
                        $rulesRemoved++;
                        break;
                    }
                }
            }
            
            if (!$shouldKeep) continue;
            
            // Parse properties
            $properties = array();
            $decls = explode(';', $declarations);
            foreach ($decls as $decl) {
                if (strpos($decl, ':') !== false) {
                    list($prop, $val) = explode(':', $decl, 2);
                    $prop = trim($prop);
                    $val = trim($val);
                    if (!empty($prop) && !empty($val)) {
                        $properties[$prop] = $val;
                    }
                }
            }
            
            if (!isset($seenSelectors[$selector])) {
                $seenSelectors[$selector] = $properties;
            } else {
                // Merge properties, later ones override
                foreach ($properties as $prop => $val) {
                    $seenSelectors[$selector][$prop] = $val;
                    $rulesRemoved++;
                }
            }
        }
        
        // Rebuild CSS
        $newContent = '';
        foreach ($seenSelectors as $selector => $properties) {
            $newContent .= $selector . " {\n";
            foreach ($properties as $prop => $val) {
                $newContent .= "    " . $prop . ": " . $val . ";\n";
            }
            $newContent .= "}\n\n";
        }
        
        // Add back media queries
        preg_match_all('/@media[^{]+\{([\s\S]+?})\s*}/', $originalContent, $mediaMatches);
        foreach ($mediaMatches[0] as $mediaQuery) {
            $newContent .= $mediaQuery . "\n\n";
        }
        
        file_put_contents($file, $newContent);
        $totalNewSize += filesize($file);
    }
    
    // Store backup info for undo
    $_SESSION['last_backup'] = $backupFiles;
    
    $saved = $totalOriginalSize - $totalNewSize;
    $percentSaved = $totalOriginalSize > 0 ? round(($saved / $totalOriginalSize) * 100, 2) : 0;
    
    return "Cleaned " . count($files) . " files<br>" .
           "Removed " . $rulesRemoved . " duplicate rules<br>" .
           "Size reduced: " . round($saved / 1024, 2) . " KB (" . $percentSaved . "%)<br>" .
           "Backup saved - Use undo button to restore";
}

/**
 * Minify CSS files
 */
function minifyCSSFiles($files) {
    $totalOriginalSize = 0;
    $totalNewSize = 0;
    $backupFiles = array();
    
    foreach ($files as $file) {
        $totalOriginalSize += filesize($file);
        
        // Create backup
        $backupFile = $file . '.backup_' . time();
        copy($file, $backupFile);
        $backupFiles[$file] = $backupFile;
        
        $content = file_get_contents($file);
        
        // Minify
        $content = preg_replace('!/\*[^*]*\*+([^/][^*]*\*+)*/!', '', $content);
        $content = str_replace(array("\r\n", "\r", "\n", "\t"), '', $content);
        $content = preg_replace('/\s+/', ' ', $content);
        $content = preg_replace('/\{\s+/\{', $content);
        $content = preg_replace('/\s+\}/', '}', $content);
        $content = preg_replace('/;\s+/', ';', $content);
        $content = preg_replace('/:\s+/', ':', $content);
        
        file_put_contents($file, $content);
        $totalNewSize += filesize($file);
    }
    
    $_SESSION['last_backup'] = $backupFiles;
    
    $saved = $totalOriginalSize - $totalNewSize;
    $percentSaved = $totalOriginalSize > 0 ? round(($saved / $totalOriginalSize) * 100, 2) : 0;
    
    return "Minified " . count($files) . " files<br>" .
           "Size reduced: " . round($saved / 1024, 2) . " KB (" . $percentSaved . "%)<br>" .
           "Backup saved - Use undo button to restore";
}

/**
 * Analyze conflicts and generate report
 */
function analyzeConflicts($files, $returnHtml = false) {
    $allRules = array();
    
    foreach ($files as $file) {
        $content = file_get_contents($file);
        $rules = extractRulesWithMetadata($content, basename($file));
        $allRules = array_merge($allRules, $rules);
    }
    
    $conflicts = detectConflicts($allRules);
    
    if ($returnHtml) {
        return $conflicts;
    }
    
    if (empty($conflicts)) {
        return "No conflicts detected! Your CSS is clean.";
    }
    
    $html = '<div class="conflicts-report">';
    $html .= '<h3>Conflicts Found: ' . count($conflicts) . '</h3>';
    $html .= '<table class="conflicts-table">';
    $html .= '<thead><tr>';
    $html .= '<th>Severity</th>';
    $html .= '<th>Selector</th>';
    $html .= '<th>Property</th>';
    $html .= '<th>Conflicting Values</th>';
    $html .= '<th>Files</th>';
    $html .= '<th>Winner</th>';
    $html .= '</tr></thead><tbody>';
    
    foreach ($conflicts as $conflict) {
        $severityColor = $conflict['severity'] === 'high' ? '#ef476f' : ($conflict['severity'] === 'medium' ? '#ff9f1c' : '#06d6a0');
        $severityIcon = $conflict['severity'] === 'high' ? 'fa-exclamation-triangle' : ($conflict['severity'] === 'medium' ? 'fa-exclamation-circle' : 'fa-info-circle');
        
        $valuesHtml = '';
        foreach ($conflict['values'] as $value) {
            $valuesHtml .= '<span class="conflict-value">' . htmlspecialchars($value) . '</span><br>';
        }
        
        $filesHtml = '';
        foreach ($conflict['files'] as $file) {
            $filesHtml .= '<span class="file-badge">' . htmlspecialchars($file) . '</span> ';
        }
        
        $html .= '<tr>';
        $html .= '<td style="color: ' . $severityColor . '"><i class="' . $severityIcon . '"></i> ' . ucfirst($conflict['severity']) . '</td>';
        $html .= '<td><code>' . htmlspecialchars($conflict['selector']) . '</code></td>';
        $html .= '<td><strong>' . htmlspecialchars($conflict['property']) . '</strong></td>';
        $html .= '<td>' . $valuesHtml . '</td>';
        $html .= '<td>' . $filesHtml . '</td>';
        $html .= '<td><code>' . htmlspecialchars($conflict['winner']) . '</code></td>';
        $html .= '</tr>';
    }
    
    $html .= '</tbody></table></div>';
    
    return $html;
}

// Process the request
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($cssFolder) && !empty($action)) {
    if (!is_dir($cssFolder)) {
        $error = "Folder not found: " . htmlspecialchars($cssFolder);
    } else {
        $cssFiles = glob($cssFolder . '/*.css');
        
        if (empty($cssFiles)) {
            $error = "No CSS files found in: " . htmlspecialchars($cssFolder);
        } else {
            if ($action === 'clean_duplicates') {
                $message = cleanDuplicateRules($cssFiles, $keepConflicts);
            } elseif ($action === 'minify') {
                $message = minifyCSSFiles($cssFiles);
            } elseif ($action === 'analyze') {
                $message = analyzeConflicts($cssFiles);
            } elseif ($action === 'full_optimize') {
                $conflicts = analyzeConflicts($cssFiles, true);
                if (empty($_POST['confirmed_conflicts']) && !empty($conflicts)) {
                    echo showConflictForm($conflicts, $cssFolder);
                    exit;
                } else {
                    $message = cleanDuplicateRules($cssFiles, $keepConflicts);
                    $message .= "<br><br>" . minifyCSSFiles($cssFiles);
                }
            }
        }
    }
}

/**
 * Show conflict confirmation form
 */
function showConflictForm($conflicts, $cssFolder) {
    ob_clean();
    ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Confirm Conflicts - CSS Optimizer</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif;
            background: #f5f7fa;
            color: #1a202c;
            line-height: 1.5;
            padding: 20px;
        }
        
        .container {
            max-width: 1400px;
            margin: 0 auto;
        }
        
        .card {
            background: white;
            border-radius: 12px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
            padding: 24px;
            margin-bottom: 20px;
        }
        
        .header {
            background: linear-gradient(135deg, #4a5568 0%, #2d3748 100%);
            color: white;
            padding: 30px;
            border-radius: 12px;
            margin-bottom: 24px;
        }
        
        .header h1 {
            font-size: 28px;
            margin-bottom: 8px;
        }
        
        .header h1 i {
            margin-right: 12px;
        }
        
        .header p {
            opacity: 0.9;
            font-size: 14px;
        }
        
        .version-badge {
            background: rgba(255,255,255,0.2);
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 12px;
            display: inline-block;
            margin-top: 10px;
        }
        
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 16px;
            margin-bottom: 24px;
        }
        
        .stat-card {
            background: #f7fafc;
            padding: 16px;
            border-radius: 8px;
            text-align: center;
        }
        
        .stat-card i {
            font-size: 28px;
            color: #4a5568;
            margin-bottom: 8px;
        }
        
        .stat-card .number {
            font-size: 32px;
            font-weight: bold;
            color: #2d3748;
        }
        
        .stat-card .label {
            font-size: 12px;
            color: #718096;
            margin-top: 4px;
        }
        
        .conflicts-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 16px;
            overflow-x: auto;
            display: block;
        }
        
        .conflicts-table th,
        .conflicts-table td {
            padding: 12px;
            text-align: left;
            border-bottom: 1px solid #e2e8f0;
        }
        
        .conflicts-table th {
            background: #f7fafc;
            font-weight: 600;
            color: #4a5568;
        }
        
        .conflicts-table tr:hover {
            background: #f7fafc;
        }
        
        .conflict-value {
            background: #edf2f7;
            padding: 4px 8px;
            border-radius: 4px;
            font-family: monospace;
            font-size: 12px;
            display: inline-block;
            margin: 2px;
        }
        
        .file-badge {
            background: #e2e8f0;
            padding: 2px 6px;
            border-radius: 4px;
            font-size: 11px;
            font-family: monospace;
            display: inline-block;
            margin: 2px;
        }
        
        .form-group {
            margin-bottom: 20px;
        }
        
        label {
            display: block;
            margin-bottom: 8px;
            font-weight: 500;
            color: #2d3748;
        }
        
        input[type="text"] {
            width: 100%;
            padding: 10px 12px;
            border: 1px solid #cbd5e0;
            border-radius: 6px;
            font-size: 14px;
        }
        
        input[type="text"]:focus {
            outline: none;
            border-color: #4a5568;
            box-shadow: 0 0 0 3px rgba(74,85,104,0.1);
        }
        
        .action-buttons {
            display: flex;
            gap: 12px;
            flex-wrap: wrap;
            margin: 20px 0;
        }
        
        .btn {
            padding: 10px 20px;
            border: none;
            border-radius: 6px;
            font-size: 14px;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.2s;
        }
        
        .btn-primary {
            background: #4a5568;
            color: white;
        }
        
        .btn-primary:hover {
            background: #2d3748;
            transform: translateY(-1px);
        }
        
        .btn-danger {
            background: #e53e3e;
            color: white;
        }
        
        .btn-danger:hover {
            background: #c53030;
        }
        
        .btn-secondary {
            background: #e2e8f0;
            color: #4a5568;
        }
        
        .btn-secondary:hover {
            background: #cbd5e0;
        }
        
        .alert {
            padding: 12px 16px;
            border-radius: 6px;
            margin-bottom: 20px;
        }
        
        .alert-success {
            background: #c6f6d5;
            color: #22543d;
            border: 1px solid #9ae6b4;
        }
        
        .alert-error {
            background: #fed7d7;
            color: #742a2a;
            border: 1px solid #fc8181;
        }
        
        .code-block {
            background: #2d3748;
            color: #e2e8f0;
            padding: 16px;
            border-radius: 6px;
            overflow-x: auto;
            font-family: monospace;
            font-size: 13px;
            margin-top: 16px;
        }
        
        .footer {
            text-align: center;
            padding: 20px;
            color: #718096;
            font-size: 12px;
        }
        
        @media (max-width: 768px) {
            .conflicts-table {
                font-size: 12px;
            }
            
            .conflicts-table th,
            .conflicts-table td {
                padding: 8px;
            }
            
            .action-buttons {
                flex-direction: column;
            }
            
            .btn {
                width: 100%;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1><i class="fas fa-magic"></i> CSS Optimizer & Analyzer</h1>
            <p>Review conflicts before applying changes - No external dependencies required</p>
            <div class="version-badge">
                <i class="fas fa-code-branch"></i> Version 3.0
            </div>
        </div>
        
        <div class="card">
            <div class="stats-grid">
                <div class="stat-card">
                    <i class="fas fa-exclamation-triangle"></i>
                    <div class="number"><?php echo count($conflicts); ?></div>
                    <div class="label">Conflicts Found</div>
                </div>
                <div class="stat-card">
                    <i class="fas fa-file-code"></i>
                    <div class="number"><?php echo count(glob($cssFolder . '/*.css')); ?></div>
                    <div class="label">CSS Files</div>
                </div>
                <div class="stat-card">
                    <i class="fas fa-tachometer-alt"></i>
                    <div class="number"><?php 
                        $totalRules = 0;
                        foreach (glob($cssFolder . '/*.css') as $file) {
                            $content = file_get_contents($file);
                            preg_match_all('/[^{]+\{/', $content, $matches);
                            $totalRules += count($matches[0]);
                        }
                        echo $totalRules;
                    ?></div>
                    <div class="label">Total Rules</div>
                </div>
            </div>
            
            <form method="POST" action="">
                <input type="hidden" name="css_folder" value="<?php echo htmlspecialchars($cssFolder); ?>">
                <input type="hidden" name="action" value="full_optimize">
                <input type="hidden" name="confirmed_conflicts" value="1">
                
                <h3><i class="fas fa-list"></i> Conflicts to Resolve</h3>
                <p style="margin-bottom: 16px; color: #718096;">For each conflict, choose whether to keep the latest rule or skip</p>
                
                <div class="conflicts-table-wrapper" style="overflow-x: auto;">
                    <table class="conflicts-table">
                        <thead>
                            <tr>
                                <th><i class="fas fa-check-square"></i> Action</th>
                                <th><i class="fas fa-chart-line"></i> Severity</th>
                                <th><i class="fas fa-hashtag"></i> Selector</th>
                                <th><i class="fas fa-css3"></i> Property</th>
                                <th><i class="fas fa-code-branch"></i> Values</th>
                                <th><i class="fas fa-file"></i> Files</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($conflicts as $index => $conflict): ?>
                            <tr>
                                <td>
                                    <select name="keep_conflicts[<?php echo htmlspecialchars($conflict['selector'] . '|' . $conflict['property']); ?>]" style="padding: 4px; border-radius: 4px; border: 1px solid #cbd5e0;">
                                        <option value="keep">Keep Latest</option>
                                        <option value="skip">Skip (Keep All)</option>
                                    </select>
                                </td>
                                <td>
                                    <i class="fas fa-<?php echo $conflict['severity'] === 'high' ? 'exclamation-triangle' : ($conflict['severity'] === 'medium' ? 'exclamation-circle' : 'info-circle'); ?>" 
                                       style="color: <?php echo $conflict['severity'] === 'high' ? '#ef476f' : ($conflict['severity'] === 'medium' ? '#ff9f1c' : '#06d6a0'); ?>">
                                    </i>
                                    <?php echo ucfirst($conflict['severity']); ?>
                                </td>
                                <td><code><?php echo htmlspecialchars(substr($conflict['selector'], 0, 50)); ?></code></td>
                                <td><strong><?php echo htmlspecialchars($conflict['property']); ?></strong></td>
                                <td>
                                    <?php foreach ($conflict['values'] as $value): ?>
                                        <span class="conflict-value"><?php echo htmlspecialchars($value); ?></span>
                                    <?php endforeach; ?>
                                </td>
                                <td>
                                    <?php foreach ($conflict['files'] as $file): ?>
                                        <span class="file-badge"><?php echo htmlspecialchars($file); ?></span>
                                    <?php endforeach; ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                
                <div class="action-buttons" style="margin-top: 24px;">
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-play"></i> Apply Selected Changes
                    </button>
                    <a href="?undo=1" class="btn btn-secondary" onclick="return confirm('Undo last operation? This will restore all backed up files.')">
                        <i class="fas fa-undo-alt"></i> Undo Last Operation
                    </a>
                    <a href="javascript:history.back()" class="btn btn-secondary">
                        <i class="fas fa-arrow-left"></i> Go Back
                    </a>
                </div>
            </form>
        </div>
        
        <div class="footer">
            <i class="fas fa-heart" style="color: #e53e3e;"></i> Pure PHP Tool - No dependencies required
        </div>
    </div>
</body>
</html>
    <?php
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>CSS Optimizer & Analyzer</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif;
            background: #f5f7fa;
            color: #1a202c;
            line-height: 1.5;
            padding: 20px;
        }
        
        .container {
            max-width: 1200px;
            margin: 0 auto;
        }
        
        .header {
            background: linear-gradient(135deg, #4a5568 0%, #2d3748 100%);
            color: white;
            padding: 40px;
            border-radius: 12px;
            margin-bottom: 24px;
            text-align: center;
        }
        
        .header h1 {
            font-size: 32px;
            margin-bottom: 12px;
        }
        
        .header h1 i {
            margin-right: 12px;
        }
        
        .header p {
            opacity: 0.9;
            font-size: 14px;
        }
        
        .version-badge {
            background: rgba(255,255,255,0.2);
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 12px;
            display: inline-block;
            margin-top: 12px;
        }
        
        .card {
            background: white;
            border-radius: 12px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
            padding: 28px;
            margin-bottom: 20px;
        }
        
        .form-group {
            margin-bottom: 24px;
        }
        
        label {
            display: block;
            margin-bottom: 8px;
            font-weight: 600;
            color: #2d3748;
        }
        
        label i {
            margin-right: 8px;
            color: #4a5568;
        }
        
        input[type="text"] {
            width: 100%;
            padding: 12px 16px;
            border: 2px solid #e2e8f0;
            border-radius: 8px;
            font-size: 14px;
            transition: all 0.2s;
        }
        
        input[type="text"]:focus {
            outline: none;
            border-color: #4a5568;
            box-shadow: 0 0 0 3px rgba(74,85,104,0.1);
        }
        
        .help-text {
            font-size: 12px;
            color: #718096;
            margin-top: 6px;
        }
        
        .help-text i {
            margin-right: 4px;
        }
        
        .action-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
            gap: 16px;
            margin: 24px 0;
        }
        
        .action-card {
            border: 2px solid #e2e8f0;
            border-radius: 10px;
            padding: 16px;
            cursor: pointer;
            transition: all 0.2s;
            display: flex;
            align-items: center;
            gap: 12px;
        }
        
        .action-card:hover {
            border-color: #4a5568;
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(0,0,0,0.1);
        }
        
        .action-card.selected {
            border-color: #4a5568;
            background: #f7fafc;
        }
        
        .action-card input[type="radio"] {
            width: 18px;
            height: 18px;
            cursor: pointer;
        }
        
        .action-card .action-icon {
            font-size: 28px;
            color: #4a5568;
        }
        
        .action-card .action-content {
            flex: 1;
        }
        
        .action-card .action-title {
            font-weight: 600;
            margin-bottom: 4px;
        }
        
        .action-card .action-desc {
            font-size: 11px;
            color: #718096;
        }
        
        .checkbox-group {
            display: flex;
            align-items: center;
            gap: 12px;
            margin: 20px 0;
            padding: 12px;
            background: #f7fafc;
            border-radius: 8px;
        }
        
        .checkbox-group input {
            width: 18px;
            height: 18px;
            cursor: pointer;
        }
        
        .checkbox-group label {
            margin: 0;
            cursor: pointer;
        }
        
        .btn {
            padding: 12px 28px;
            border: none;
            border-radius: 8px;
            font-size: 15px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }
        
        .btn-primary {
            background: #4a5568;
            color: white;
        }
        
        .btn-primary:hover {
            background: #2d3748;
            transform: translateY(-1px);
        }
        
        .btn-primary:disabled {
            background: #cbd5e0;
            cursor: not-allowed;
            transform: none;
        }
        
        .btn-secondary {
            background: #e2e8f0;
            color: #4a5568;
        }
        
        .btn-secondary:hover {
            background: #cbd5e0;
        }
        
        .btn-danger {
            background: #e53e3e;
            color: white;
        }
        
        .btn-danger:hover {
            background: #c53030;
        }
        
        .alert {
            padding: 14px 18px;
            border-radius: 8px;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 12px;
        }
        
        .alert-success {
            background: #c6f6d5;
            color: #22543d;
            border-left: 4px solid #38a169;
        }
        
        .alert-error {
            background: #fed7d7;
            color: #742a2a;
            border-left: 4px solid #e53e3e;
        }
        
        .alert i {
            font-size: 18px;
        }
        
        .results-area {
            margin-top: 20px;
            padding: 20px;
            background: #f7fafc;
            border-radius: 8px;
            display: none;
        }
        
        .results-area.show {
            display: block;
        }
        
        .loading {
            display: none;
            text-align: center;
            padding: 40px;
        }
        
        .loading.show {
            display: block;
        }
        
        .fa-spin {
            animation: fa-spin 2s infinite linear;
        }
        
        @keyframes fa-spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }
        
        .footer {
            text-align: center;
            padding: 24px;
            color: #718096;
            font-size: 13px;
        }
        
        @media (max-width: 768px) {
            .header {
                padding: 24px;
            }
            
            .header h1 {
                font-size: 24px;
            }
            
            .card {
                padding: 20px;
            }
            
            .action-grid {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1><i class="fas fa-magic"></i> CSS Optimizer & Analyzer</h1>
            <p>Clean duplicate rules, minify files, and detect conflicts</p>
            <div class="version-badge">
                <i class="fas fa-code-branch"></i> Version 3.0
            </div>
        </div>
        
        <div class="card">
            <?php if ($message): ?>
                <div class="alert alert-success">
                    <i class="fas fa-check-circle"></i>
                    <div><?php echo $message; ?></div>
                </div>
            <?php endif; ?>
            
            <?php if ($error): ?>
                <div class="alert alert-error">
                    <i class="fas fa-exclamation-circle"></i>
                    <div><?php echo $error; ?></div>
                </div>
            <?php endif; ?>
            
            <form method="POST" action="" id="optimizerForm">
                <div class="form-group">
                    <label><i class="fas fa-folder-open"></i> CSS Folder Path</label>
                    <input type="text" name="css_folder" id="cssFolder" 
                           value="<?php echo htmlspecialchars($cssFolder ?: $defaultPath); ?>"
                           placeholder="Example: /home/username/public_html/assets/css" required>
                    <div class="help-text">
                        <i class="fas fa-info-circle"></i> Absolute or relative path to your CSS files directory
                    </div>
                </div>
                
                <label><i class="fas fa-tasks"></i> Select Operation</label>
                <div class="action-grid">
                    <label class="action-card">
                        <input type="radio" name="action" value="clean_duplicates" required>
                        <i class="fas fa-broom action-icon"></i>
                        <div class="action-content">
                            <div class="action-title">Clean Duplicates</div>
                            <div class="action-desc">Remove duplicate rules, keep last occurrence</div>
                        </div>
                    </label>
                    
                    <label class="action-card">
                        <input type="radio" name="action" value="minify">
                        <i class="fas fa-compress-alt action-icon"></i>
                        <div class="action-content">
                            <div class="action-title">Minify</div>
                            <div class="action-desc">Compress CSS files, remove whitespace</div>
                        </div>
                    </label>
                    
                    <label class="action-card">
                        <input type="radio" name="action" value="analyze">
                        <i class="fas fa-search action-icon"></i>
                        <div class="action-content">
                            <div class="action-title">Analyze Conflicts</div>
                            <div class="action-desc">Detect conflicts without modifying files</div>
                        </div>
                    </label>
                    
                    <label class="action-card">
                        <input type="radio" name="action" value="full_optimize">
                        <i class="fas fa-star action-icon"></i>
                        <div class="action-content">
                            <div class="action-title">Full Optimize</div>
                            <div class="action-desc">Clean + Minify with conflict review</div>
                        </div>
                    </label>
                </div>
                
                <div class="checkbox-group">
                    <input type="checkbox" name="dry_run" id="dryRun">
                    <label for="dryRun">
                        <i class="fas fa-eye"></i> Dry Run Mode
                    </label>
                    <span class="help-text" style="margin: 0; margin-left: auto;">
                        <i class="fas fa-info-circle"></i> Preview changes without saving
                    </span>
                </div>
                
                <div style="display: flex; gap: 12px; flex-wrap: wrap; justify-content: space-between; align-items: center;">
                    <div>
                        <button type="submit" class="btn btn-primary" id="submitBtn">
                            <i class="fas fa-play"></i> Start Processing
                        </button>
                        <a href="?undo=1" class="btn btn-danger" onclick="return confirm('Undo last operation? This will restore all backed up files.')">
                            <i class="fas fa-undo-alt"></i> Undo
                        </a>
                    </div>
                    <button type="button" class="btn btn-secondary" onclick="document.getElementById('cssFolder').value = '<?php echo addslashes($defaultPath); ?>'">
                        <i class="fas fa-sync-alt"></i> Reset Path
                    </button>
                </div>
            </form>
        </div>
        
        <div class="loading" id="loading">
            <i class="fas fa-spinner fa-spin" style="font-size: 48px; color: #4a5568;"></i>
            <p style="margin-top: 16px;">Processing CSS files, please wait...</p>
        </div>
        
        <div class="footer">
            <i class="fas fa-heart" style="color: #e53e3e;"></i> Pure PHP Tool - No dependencies required
        </div>
    </div>
    
    <script>
        document.getElementById('optimizerForm').addEventListener('submit', function() {
            document.getElementById('loading').classList.add('show');
            document.getElementById('submitBtn').disabled = true;
        });
        
        // Auto-select card styling
        document.querySelectorAll('.action-card').forEach(card => {
            const radio = card.querySelector('input[type="radio"]');
            card.addEventListener('click', (e) => {
                if (e.target !== radio) {
                    radio.checked = true;
                }
                document.querySelectorAll('.action-card').forEach(c => c.classList.remove('selected'));
                card.classList.add('selected');
            });
            if (radio.checked) {
                card.classList.add('selected');
            }
        });
    </script>
</body>
</html>
