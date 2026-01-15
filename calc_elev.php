<?php
/**
 * Calculadora (pre-dimensionamiento) para elevador residencial de tracción con contrapeso
 * Variables de entrada: paradas, carga_nominal (kg), recorrido (m)
 * Estándar: hueco 1.5 x 1.5 m, foso 0.20 m (200 mm) bajo piso terminado
 *
 * ⚠️ AVISO IMPORTANTE
 * Esto NO sustituye cálculo estructural, verificación normativa (NOM/ASME/EN), ni ingeniería de fabricante.
 * Es un "sizing" inicial para iterar conceptos y seleccionar rangos de equipo.
 *
 * Salida: Requisitos estructurales aproximados + características sugeridas de motor y seguridad.
 *
 * Uso:
 *  - Web: /calc.php?paradas=2&carga=400&recorrido=4
 *  - CLI: php calc.php paradas=2 carga=400 recorrido=4
 */

header('Content-Type: application/json; charset=utf-8');

// -----------------------
// Helpers
// -----------------------
function get_param(string $key, $default = null) {
    // CLI style: key=value
    if (PHP_SAPI === 'cli') {
        global $argv;
        foreach ($argv as $arg) {
            if (strpos($arg, $key.'=') === 0) {
                return substr($arg, strlen($key) + 1);
            }
        }
    }
    // Web style
    return $_GET[$key] ?? $default;
}

function clamp($v, $min, $max) {
    return max($min, min($max, $v));
}

function round_sig($value, $sig = 3) {
    if ($value == 0) return 0;
    $mult = pow(10, $sig - floor(log10(abs($value))) - 1);
    return round($value * $mult) / $mult;
}

function warn(array &$warnings, string $msg) {
    $warnings[] = $msg;
}

// -----------------------
// Inputs
// -----------------------
$stops   = (int) get_param('paradas', 2);
$loadKg  = (float) get_param('carga', 400);
$travelM = (float) get_param('recorrido', 4);

$warnings = [];
$errors = [];

// Basic validation
if ($stops < 2) $errors[] = "paradas debe ser >= 2.";
if ($loadKg <= 0) $errors[] = "carga (kg) debe ser > 0.";
if ($travelM <= 0) $errors[] = "recorrido (m) debe ser > 0.";

if (!empty($errors)) {
    echo json_encode([
        "ok" => false,
        "errors" => $errors
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    exit;
}

// -----------------------
// Fixed "standard shaft" geometry
// -----------------------
$shaftWidth_m  = 1.5;   // 1.5 m
$shaftDepth_m  = 1.5;   // 1.5 m
$pitDepth_m    = 0.20;  // 20 cm under floor (user requested)
$doorClear_m   = 0.80;  // typical clear opening for residential swing door
$cabinWall_t_m = 0.03;  // ~30 mm panel+structure (assumption)

// -----------------------
// Assumptions (tunable constants)
// -----------------------
$g = 9.81;

// Speed recommendation based on "residential low cost"
$v_mps = 0.35; // default 0.35 m/s

// If travel small, speed can be a bit lower
if ($travelM < 3.0) $v_mps = 0.30;
if ($travelM > 8.0) $v_mps = 0.45;

// Cabin internal size suggestion within 1.5x1.5 shaft
// We'll reserve space for counterweight column and clearances
$counterweightColumn_m = 0.35;   // 350 mm
$railZone_m            = 0.10;   // guide rail + clearances per side (simplified)
$runningClear_m        = 0.05;   // clearances

// Cabin external envelope estimation
$cabinExtWidth_m = $shaftWidth_m - $counterweightColumn_m - $railZone_m - $runningClear_m; // side-by-side
$cabinExtDepth_m = $shaftDepth_m - $railZone_m - $runningClear_m;

// cabin internal
$cabinIntWidth_m = max(0.75, $cabinExtWidth_m - 2*$cabinWall_t_m);
$cabinIntDepth_m = max(0.90, $cabinExtDepth_m - 2*$cabinWall_t_m);

// Cabin + sling mass estimate (kg)
// Rule-of-thumb: base + factor*(cabin area) + factor*(rated load)
$cabinArea_m2 = $cabinIntWidth_m * $cabinIntDepth_m;
$cabinMass_kg = 250 + 120*$cabinArea_m2 + 0.15*$loadKg; // typical residential lightweight-ish
$cabinMass_kg = clamp($cabinMass_kg, 300, 650);

// Counterweight ratio (45–50% rated load)
$counterRatio = 0.50;
$counterMass_kg = $cabinMass_kg + $counterRatio*$loadKg;

// Roping
$roping = "1:1";

// Efficiency & friction allowance
$eta_total = 0.70; // combined mech+electrical
$frictionFactor = 0.08; // fraction of total moving mass as equivalent friction load

// Acceleration assumption
$a_mps2 = 0.6; // m/s^2 (comfortable residential)
$a_mps2 = clamp($a_mps2, 0.4, 1.0);

// -----------------------
// Basic kinematics
// -----------------------
$floorToFloor_m = $travelM / ($stops - 1);
if ($floorToFloor_m < 2.2) {
    warn($warnings, "La distancia entre paradas calculada (~".round($floorToFloor_m,2)." m) es baja; revisa alturas de entrepiso reales.");
}

// Ride time estimate
$time_s = $travelM / max(0.1, $v_mps);

// -----------------------
// Loads & power (approx)
// -----------------------

// Worst-case unbalance: full load in cabin going up (counterweight at 50%)
$unbalance_kg = ($cabinMass_kg + $loadKg) - $counterMass_kg; // positive => heavier cabin side
// friction equivalent
$movingMass_kg = ($cabinMass_kg + $loadKg) + $counterMass_kg; // both sides "moving"
$friction_kg = $frictionFactor * $movingMass_kg;

// Effective load (kg) to lift at constant speed
$effectiveLift_kg = $unbalance_kg + $friction_kg; // simplified

// Force (N)
$F_N = $effectiveLift_kg * $g;

// Power at constant speed (W)
$P_W = $F_N * $v_mps / max(0.01, $eta_total);

// Add acceleration peak: F_add = (m_unbalance_equiv)*a
// Very rough: use moving mass fraction
$F_acc_N = (0.25 * $movingMass_kg) * $a_mps2; // heuristic
$P_peak_W = ($F_N + $F_acc_N) * $v_mps / max(0.01, $eta_total);

// Motor kW suggestion with margin
$motor_kW = max(2.2, $P_peak_W / 1000.0 * 1.25);
if ($motor_kW < 3.0) $motor_kW = 3.0; // typical minimum practical
$motor_kW = round_sig($motor_kW, 3);

// -----------------------
// Rope sizing heuristic
// -----------------------
// Suspension load = heavier side max (cabin+load) as force
$suspensionLoad_N = ($cabinMass_kg + $loadKg) * $g;

// Choose rope diameter and count from simple table-like rules
// NOTE: real selection depends on traction sheave, groove, D/d ratio, standards, etc.
$ropeOptions = [
    ["d_mm" => 8,  "min_break_kN" => 30], // very rough per rope
    ["d_mm" => 10, "min_break_kN" => 45],
    ["d_mm" => 12, "min_break_kN" => 65],
];
$safetyFactorRopes = 12; // typical order-of-magnitude for suspension (varies by standard)
$requiredTotalBreak_kN = ($suspensionLoad_N / 1000.0) * $safetyFactorRopes;

$selected = null;
foreach ($ropeOptions as $opt) {
    for ($n = 3; $n <= 8; $n++) {
        $totalBreak_kN = $n * $opt["min_break_kN"];
        if ($totalBreak_kN >= $requiredTotalBreak_kN) {
            $selected = [
                "rope_diameter_mm" => $opt["d_mm"],
                "rope_count" => $n,
                "estimated_total_break_kN" => $totalBreak_kN,
                "required_total_break_kN" => round_sig($requiredTotalBreak_kN, 3),
                "assumed_safety_factor" => $safetyFactorRopes
            ];
            break 2;
        }
    }
}

if ($selected === null) {
    warn($warnings, "No se pudo seleccionar cuerda con la tabla heurística; aumenta número/diámetro o revisa supuestos.");
    $selected = [
        "rope_diameter_mm" => 12,
        "rope_count" => 8,
        "estimated_total_break_kN" => 8*65,
        "required_total_break_kN" => round_sig($requiredTotalBreak_kN, 3),
        "assumed_safety_factor" => $safetyFactorRopes
    ];
}

// -----------------------
// Guide rail & bracket spacing heuristic
// -----------------------
// Very simplified: bracket spacing based on load class & travel
if ($loadKg <= 300) {
    $railType = "T70-1 (referencial)";
    $bracketSpacing_m = 2.0;
} elseif ($loadKg <= 450) {
    $railType = "T75-3 / T82 (referencial)";
    $bracketSpacing_m = 1.8;
} else {
    $railType = "T89 / T90 (referencial)";
    $bracketSpacing_m = 1.6;
}

$bracketSpacing_m = clamp($bracketSpacing_m, 1.5, 2.0);

// Approx number of brackets per rail
// total rail length includes travel + pit + overhead allowance
$overheadAllow_m = 3.2; // typical safe-ish overhead; user didn't specify, assume for estimate
$totalGuideLength_m = $travelM + $pitDepth_m + $overheadAllow_m;
$bracketsPerRail = (int) ceil($totalGuideLength_m / $bracketSpacing_m) + 1;

// Bracket load heuristic
// Assume lateral impact load fraction of rated load (very rough)
$lateralLoad_N = 0.15 * ($loadKg * $g);
$bracketDesignLoad_N = $lateralLoad_N / max(1, $bracketsPerRail/2); // distributed
$bracketDesignLoad_N = max($bracketDesignLoad_N, 1500); // minimum design load heuristic

// -----------------------
// Top beam reaction estimate
// -----------------------
// If machine mounted on top beam, approximate vertical reaction equals sum of rope tensions near sheave.
// For 1:1 traction, per rope tension ~ suspensionLoad/rope_count (very rough), total ~ suspensionLoad.
$topBeamVertical_N = 1.2 * $suspensionLoad_N; // include dynamic factor
$topBeamVertical_kN = round_sig($topBeamVertical_N/1000.0, 3);

// -----------------------
// Safety devices recommendation
// -----------------------
$safety = [];

// Governor speed recommendation
$governorTrip_mps = 1.15 * $v_mps; // typical 115% of rated speed order-of-magnitude
$governorTrip_mps = round_sig($governorTrip_mps, 3);

$safety["overspeed_governor"] = [
    "type" => "Gobernador centrífugo (mecánico) con cuerda + tensora",
    "rated_speed_mps" => $v_mps,
    "recommended_trip_speed_mps" => $governorTrip_mps,
    "notes" => "El ajuste real depende del kit de gobernador/paracaídas del proveedor y norma aplicable."
];

// Safety gear type
if ($v_mps <= 0.63) {
    $safetyGearType = "Paracaídas progresivo (recomendado) o instantáneo con amortiguación (según kit)";
} else {
    $safetyGearType = "Paracaídas progresivo (requerido típicamente a mayor velocidad)";
}
$safety["safety_gear"] = [
    "type" => $safetyGearType,
    "mounting" => "En bastidor (sling) actuando sobre guías de cabina",
    "trigger" => "Activación por gobernador + varillaje/levas del kit"
];

$safety["door_interlocks"] = [
    "type" => "Cerraduras de puerta (interlocks) de seguridad para puertas abatibles",
    "quantity" => $stops,
    "notes" => "Cada puerta de piso debe enclavar mecánicamente y dar contacto eléctrico de seguridad."
];

$safety["limit_switches"] = [
    "normal_limits" => "Finales de carrera superior e inferior (parada/ralentización)",
    "safety_limits" => "Finales de seguridad independientes (corte de maniobra)",
    "inspection" => "Modo inspección en techo + paro de emergencia"
];

$safety["buffers"] = [
    "pit_depth_m" => $pitDepth_m,
    "recommended" => "Buffers en foso (cabina y/o contrapeso según layout).",
    "warning" => "Con foso de 0.20 m el margen es MUY limitado; revisa requerimientos de refugio y buffers."
];
if ($pitDepth_m < 0.40) {
    warn($warnings, "Foso de 0.20 m es extremadamente pequeño para un elevador de tracción convencional; puede no cumplir espacios de seguridad/refugio ni buffers adecuados.");
}

// -----------------------
// Electrical safety chain (conceptual)
$safety["safety_chain_concept"] = [
    "series_contacts" => [
        "Paro de emergencia cabina",
        "Paro techo (inspección)",
        "Paro foso",
        "Interlocks de puertas (todas)",
        "Final superior de seguridad",
        "Final inferior de seguridad",
        "Contacto gobernador/paracaídas",
        "Monitoreo de freno (si disponible)",
        "Protecciones del variador/control (según fabricante)"
    ],
    "notes" => "Implementar con relé de seguridad o entradas certificadas del controlador."
];

// -----------------------
// Output report
// -----------------------
$report = [
    "ok" => true,
    "inputs" => [
        "paradas" => $stops,
        "carga_nominal_kg" => $loadKg,
        "recorrido_m" => $travelM
    ],
    "standard_shaft" => [
        "hueco_m" => [$shaftWidth_m, $shaftDepth_m],
        "foso_m" => $pitDepth_m,
        "nota" => "Hueco estándar 1.5x1.5 m y foso 0.20 m según solicitud."
    ],
    "recommended_geometry" => [
        "configuration" => "Tracción 1:1 con contrapeso lateral + puertas abatibles",
        "door_clear_opening_m" => [$doorClear_m, 2.0],
        "cabin_internal_m" => [
            "width" => round_sig($cabinIntWidth_m, 3),
            "depth" => round_sig($cabinIntDepth_m, 3),
            "area_m2" => round_sig($cabinArea_m2, 3)
        ],
        "counterweight_column_m" => $counterweightColumn_m,
        "assumed_clearances_m" => [
            "rail_zone" => $railZone_m,
            "running_clearance" => $runningClear_m,
            "cabin_wall_thickness" => $cabinWall_t_m
        ]
    ],
    "performance" => [
        "rated_speed_mps" => $v_mps,
        "estimated_travel_time_s" => round_sig($time_s, 3),
        "floor_to_floor_m" => round_sig($floorToFloor_m, 3)
    ],
    "masses" => [
        "estimated_cabin_plus_sling_kg" => round_sig($cabinMass_kg, 3),
        "counterweight_kg" => round_sig($counterMass_kg, 3),
        "counterweight_rule" => "CW ≈ Cabina + 0.50×Carga"
    ],
    "motor_and_drive" => [
        "suggested_motor_kW" => $motor_kW,
        "drive" => "VVVF (variador) con entradas de seguridad",
        "assumptions" => [
            "eta_total" => $eta_total,
            "friction_factor" => $frictionFactor,
            "acceleration_mps2" => $a_mps2
        ],
        "note" => "La selección final depende de máquina (polea), eficiencia real y kit de control."
    ],
    "ropes" => [
        "roping" => $roping,
        "selection_heuristic" => $selected,
        "note" => "Selección REAL depende de D/d, ranura, tracción, normas y proveedor."
    ],
    "structural_requirements_estimates" => [
        "guide_rails" => [
            "suggested_rail_type" => $railType,
            "total_guide_length_m_est" => round_sig($totalGuideLength_m, 3),
            "bracket_spacing_m" => $bracketSpacing_m,
            "brackets_per_rail_est" => $bracketsPerRail,
            "bracket_design_load_N_est" => round_sig($bracketDesignLoad_N, 3),
            "notes" => "Cargas reales requieren análisis de impacto, alineación y norma."
        ],
        "top_beam" => [
            "vertical_reaction_kN_est" => $topBeamVertical_kN,
            "notes" => "Reacción real depende del arreglo de poleas, ubicación de máquina y dinámica."
        ],
        "pit_and_overhead" => [
            "pit_depth_m" => $pitDepth_m,
            "assumed_overhead_m" => $overheadAllow_m,
            "warning" => ($pitDepth_m < 0.40)
                ? "Foso muy reducido para diseño convencional; verifica espacios de seguridad y buffers."
                : "OK (aún debe verificarse con norma)."
        ]
    ],
    "safety_equipment" => $safety,
    "warnings" => $warnings
];

echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
