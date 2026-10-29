import Encore from '@symfony/webpack-encore';

if (!Encore.isRuntimeEnvironmentConfigured()) {
    Encore.configureRuntimeEnvironment(process.env.NODE_ENV || 'dev');
}

Encore
    .setOutputPath('public/build/')
    .setPublicPath('/build')

    // App-Einstiegspunkt
    .addEntry('app', './assets/app.js')

    .splitEntryChunks()
    .enableReactPreset()
    .enableStimulusBridge('./assets/controllers.json')
    .enableSingleRuntimeChunk()
    .cleanupOutputBeforeBuild()
    .enableSourceMaps(!Encore.isProduction())
    // Versioning auch im Dev-Modus: Caddy liefert .js/.css mit
    // "Cache-Control: immutable" aus (siehe Caddyfile @static). Ohne Hash im
    // Dateinamen friert der Browser app.js dauerhaft ein und zieht Code-Änderungen
    // selbst per Hard-Reload nicht. Mit Hash erzwingt jeder Build eine neue URL.
    .enableVersioning(true)

    // PostCSS für Tailwind
    .enablePostCssLoader()

    // Babel 8 kennt useBuiltIns/corejs nicht mehr; Polyfills nach Bedarf
    // liefert jetzt das core-js-Plugin.
    .configureBabel((config) => {
        config.plugins.push(['babel-plugin-polyfill-corejs3', { method: 'usage-global', version: '3.51' }]);
    })

    // Encore 7 minimiert CSS nicht mehr von sich aus.
    .configureCssMinimizerPlugin((options, MinimizerPlugin) => {
        options.minify = MinimizerPlugin.lightningCssMinify;
    })
;

export default await Encore.getWebpackConfig();
