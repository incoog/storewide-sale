module.exports = {
    testDir: './tests/browser', timeout: 60000, workers: 1,
    outputDir: 'test-results', reporter: 'list',
    use: { channel: 'chromium', headless: process.env.SWS_HEADED !== '1', baseURL: 'http://127.0.0.1:8080', viewport: { width: 1440, height: 1100 }, trace: 'retain-on-failure', screenshot: 'only-on-failure' }
};
