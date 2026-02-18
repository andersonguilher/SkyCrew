<?php
require_once '../db_connect.php';
require_once '../includes/auth_session.php';
requireRole('admin');

$id = $_GET['id'] ?? null;
if (!$id) {
    header("Location: reports.php");
    exit;
}

$stmt = $pdo->prepare("
    SELECT fr.*, p.name as pilot_name, f.flight_number, f.dep_icao, f.arr_icao 
    FROM flight_reports fr
    JOIN pilots p ON fr.pilot_id = p.id
    JOIN roster_assignments r ON fr.roster_id = r.id
    JOIN flights_master f ON r.flight_id = f.id
    WHERE fr.id = ?
");
$stmt->execute([$id]);
$report = $stmt->fetch();

if (!$report) {
    echo "Relatório não encontrado.";
    exit;
}

$logData = json_decode($report['log_json'], true);
$events = $logData['Events'] ?? [];

$pageTitle = "Detalhes do Voo " . $report['flight_number'] . " - SkyCrew OS";
$extraHead = '
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
    <style>
        #flightMap { height: 500px; border-radius: 20px; z-index: 1; }
        .leaflet-container { background: #0c0e17 !important; }
        .event-marker { filter: drop-shadow(0 0 5px rgba(0,0,0,0.5)); }
        .glass-tooltip { background: rgba(12, 14, 23, 0.9) !important; border: 1px solid rgba(255, 255, 255, 0.2) !important; color: white !important; font-size: 11px !important; border-radius: 6px !important; }
    </style>
';
include '../includes/layout_header.php';
?>

<div class="flex-1 flex flex-col space-y-6 overflow-hidden">
    <!-- Header -->
    <div class="flex justify-between items-center shrink-0">
        <div class="flex items-center gap-4">
            <a href="reports.php" class="w-10 h-10 rounded-full bg-white/5 border border-white/10 flex items-center justify-center text-slate-400 hover:text-white transition">
                <i class="fas fa-arrow-left"></i>
            </a>
            <div>
                <h2 class="text-2xl font-bold text-white flex items-center gap-3">
                    <?php echo $report['flight_number']; ?> <span class="text-slate-500 font-normal">|</span> <span class="text-indigo-400"><?php echo $report['dep_icao']; ?> → <?php echo $report['arr_icao']; ?></span>
                </h2>
                <p class="text-[10px] text-slate-500 uppercase tracking-widest mt-1">
                    Comandante: <?php echo htmlspecialchars($report['pilot_name']); ?> • PIREP #<?php echo $report['id']; ?>
                </p>
            </div>
        </div>
        <div class="flex gap-3">
             <div class="bg-indigo-500/10 border border-indigo-500/20 px-4 py-2 rounded-2xl text-center">
                <p class="text-[9px] font-bold text-indigo-400 uppercase tracking-widest mb-1">Score Final</p>
                <p class="text-xl font-black text-white"><?php echo $report['points']; ?> <span class="text-[10px] font-normal text-slate-500">pts</span></p>
            </div>
            <div class="<?php echo $report['landing_rate'] > -300 ? 'bg-emerald-500/10 border-emerald-500/20' : 'bg-rose-500/10 border-rose-500/20'; ?> border px-4 py-2 rounded-2xl text-center">
                <p class="text-[9px] font-bold <?php echo $report['landing_rate'] > -300 ? 'text-emerald-400' : 'text-rose-400'; ?> uppercase tracking-widest mb-1">Toque</p>
                <p class="text-xl font-black text-white"><?php echo $report['landing_rate']; ?> <span class="text-[10px] font-normal text-slate-500">fpm</span></p>
            </div>
        </div>
    </div>

    <!-- Main Content -->
    <div class="flex-1 grid grid-cols-1 lg:grid-cols-3 gap-6 overflow-hidden">
        <!-- Left: Map -->
        <div class="lg:col-span-2 flex flex-col space-y-4">
            <div class="glass-panel p-4 rounded-3xl flex-1 relative min-h-[400px]">
                <div id="flightMap" class="w-full h-full"></div>
            </div>
        </div>

        <!-- Right: Event Timeline -->
        <div class="glass-panel rounded-3xl flex flex-col overflow-hidden">
            <div class="p-4 border-b border-white/10 bg-white/5">
                <h3 class="text-sm font-bold text-white uppercase tracking-widest">Cronologia de Eventos</h3>
            </div>
            <div class="flex-1 overflow-y-auto p-6 space-y-6 custom-scrollbar">
                <?php if (empty($events)): ?>
                    <p class="text-center text-slate-500 italic text-sm">Nenhum evento detalhado registrado.</p>
                <?php else: ?>
                    <div class="relative border-l-2 border-white/5 ml-2 pl-6 space-y-8">
                        <?php foreach ($events as $idx => $event): ?>
                            <div class="relative">
                                <div class="absolute -left-[31px] top-1 w-4 h-4 rounded-full border-4 border-[#0c0e17] <?php echo $event['IsError'] ? 'bg-rose-500 shadow-[0_0_10px_rgba(244,63,94,0.5)]' : 'bg-indigo-500'; ?>"></div>
                                <div class="flex flex-col">
                                    <div class="flex justify-between items-center">
                                        <span class="text-[10px] font-bold <?php echo $event['IsError'] ? 'text-rose-400' : 'text-indigo-400'; ?> uppercase tracking-widest"><?php echo $event['Phase']; ?></span>
                                        <span class="text-[10px] text-slate-500 font-mono"><?php echo date('H:i:s', strtotime($event['Timestamp'])); ?> Z</span>
                                    </div>
                                    <p class="text-sm text-slate-200 mt-1"><?php echo htmlspecialchars($event['Message']); ?></p>
                                    <?php if ($event['Latitude']): ?>
                                        <p class="text-[9px] text-slate-600 font-mono mt-1"><?php echo round($event['Latitude'], 4); ?>, <?php echo round($event['Longitude'], 4); ?></p>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<script>
    const events = <?php echo json_encode($events); ?>;
    
    // Initialize map
    const map = L.map('flightMap', {
        zoomControl: false,
        attributionControl: false
    });

    L.tileLayer('https://{s}.basemaps.cartocdn.com/dark_all/{z}/{x}/{y}{r}.png', {
        maxZoom: 19
    }).addTo(map);

    if (events.length > 0) {
        const path = [];
        const markers = [];

        events.forEach((ev, i) => {
            if (ev.Latitude && ev.Longitude) {
                const pos = [ev.Latitude, ev.Longitude];
                path.push(pos);

                // Add markers for specific phases or errors
                if (i === 0 || i === events.length - 1 || ev.IsError || ['Takeoff', 'Approach', 'Landing', 'EngineStart', 'Shutdown'].includes(ev.Phase)) {
                    let iconColor = ev.IsError ? '#f43f5e' : '#6366f1';
                    if (ev.Phase === 'Takeoff') iconColor = '#fbbf24';
                    if (ev.Phase === 'Landing' || ev.Message.includes('Touchdown')) iconColor = '#10b981';

                    const marker = L.circleMarker(pos, {
                        radius: ev.IsError ? 6 : 5,
                        fillColor: iconColor,
                        color: '#fff',
                        weight: 2,
                        fillOpacity: 1,
                        className: 'event-marker'
                    }).addTo(map);

                    marker.bindTooltip(`<b>${ev.Phase}</b><br>${ev.Message}`, {
                        direction: 'top',
                        className: 'glass-tooltip',
                        offset: [0, -5]
                    });
                }
            }
        });

        if (path.length > 0) {
            // Draw flight path
            L.polyline(path, {
                color: '#6366f1',
                weight: 3,
                opacity: 0.6,
                dashArray: '5, 10'
            }).addTo(map);

            // Add Origin/Destination Labels
            const originPos = path[0];
            const destPos = path[path.length - 1];

            L.marker(originPos, { opacity: 0 }).addTo(map)
                .bindTooltip("<?php echo $report['dep_icao']; ?>", { permanent: true, direction: 'top', className: 'glass-tooltip', offset: [0, -10] });

            L.marker(destPos, { opacity: 0 }).addTo(map)
                .bindTooltip("<?php echo $report['arr_icao']; ?>", { permanent: true, direction: 'top', className: 'glass-tooltip', offset: [0, -10] });

            // Fit map to path
            map.fitBounds(L.polyline(path).getBounds(), { padding: [80, 80] });
        }
    } else {
        map.setView([-15.78, -47.92], 4);
    }
</script>

<?php include '../includes/layout_footer.php'; ?>
