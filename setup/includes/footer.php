    </main>
    <?php
    // [#108 - Dette 28] Version réelle depuis version.txt (plus de « 0.9.3 » figé).
    $versionFile = __DIR__ . '/../../version.txt';
    $epiclubVersion = file_exists($versionFile) ? trim(file_get_contents($versionFile)) : '0.0.0';
    ?>
    <footer class="container">
        <hr>
        <p class="text-center text-body-secondary">
            <a href="https://epiclub.fr">Epiclub <?= htmlspecialchars($epiclubVersion) ?> (beta)</a>
        </p>
    </footer>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>