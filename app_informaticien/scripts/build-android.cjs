const fs = require('node:fs');
const path = require('node:path');
const { spawnSync } = require('node:child_process');
const esbuild = require('esbuild');

const projectDir = path.resolve(__dirname, '..');
const rendererDir = path.join(projectDir, 'renderer');
const webDir = path.join(projectDir, 'android-web');
const sdkDir = path.join(process.env.ANDROID_HOME || path.join(process.env.LOCALAPPDATA, 'Android', 'Sdk'));
const javaHome = path.join(process.env.ProgramFiles || 'C:\\Program Files', 'Android', 'Android Studio', 'jbr');

function run(command, args, options = {}) {
  const result = spawnSync(command, args, {
    cwd: projectDir,
    stdio: 'inherit',
    shell: process.platform === 'win32',
    ...options,
  });
  if (result.error) throw result.error;
  if (result.status !== 0) throw new Error(`${command} ${args.join(' ')} exited with ${result.status}`);
}

async function main() {
  fs.mkdirSync(webDir, { recursive: true });
  fs.cpSync(rendererDir, webDir, { recursive: true });
  fs.mkdirSync(path.join(webDir, 'vendor'), { recursive: true });
  fs.copyFileSync(
    path.join(projectDir, 'node_modules', 'pdf-lib', 'dist', 'pdf-lib.min.js'),
    path.join(webDir, 'vendor', 'pdf-lib.min.js')
  );
  await esbuild.build({
    entryPoints: [path.join(__dirname, 'qrcode-browser-entry.cjs')],
    bundle: true,
    platform: 'browser',
    format: 'iife',
    outfile: path.join(webDir, 'qrcode.bundle.js'),
  });
  const htmlPath = path.join(webDir, 'index.html');
  const html = fs.readFileSync(htmlPath, 'utf8')
    .replace('../node_modules/pdf-lib/dist/pdf-lib.min.js', 'vendor/pdf-lib.min.js')
    .replace('<script src="app.js"></script>', '<script src="qrcode.bundle.js"></script>\n  <script src="app.js"></script>');
  fs.writeFileSync(htmlPath, html);

  process.env.ANDROID_HOME = sdkDir;
  process.env.ANDROID_SDK_ROOT = sdkDir;
  if (fs.existsSync(javaHome)) process.env.JAVA_HOME = javaHome;

  const npx = process.platform === 'win32' ? 'npx.cmd' : 'npx';
  const androidDir = path.join(projectDir, 'android');
  if (!fs.existsSync(androidDir)) {
    run(npx, ['cap', 'add', 'android']);
  } else {
    run(npx, ['cap', 'sync', 'android']);
  }

  const gradleWrapper = path.join(androidDir, process.platform === 'win32' ? 'gradlew.bat' : 'gradlew');
  if (process.platform === 'win32') {
    run(process.env.ComSpec || 'cmd.exe', ['/d', '/s', '/c', `"${gradleWrapper}" assembleDebug`], { cwd: androidDir });
  } else {
    run(gradleWrapper, ['assembleDebug'], { cwd: androidDir });
  }

  const builtApk = path.join(androidDir, 'app', 'build', 'outputs', 'apk', 'debug', 'app-debug.apk');
  const outputDir = path.join(projectDir, 'dist');
  const outputApk = path.join(outputDir, 'ISGI-Informaticien-debug.apk');
  if (!fs.existsSync(builtApk)) throw new Error(`APK not found at ${builtApk}`);
  fs.mkdirSync(outputDir, { recursive: true });
  fs.copyFileSync(builtApk, outputApk);
  console.log(`\nAPK ready: ${outputApk}`);
}

main().catch(error => {
  console.error(error);
  process.exitCode = 1;
});
