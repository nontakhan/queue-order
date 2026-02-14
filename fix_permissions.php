<?php
// fix_permissions.php - แก้ไขปัญหา permissions อัตโนมัติ

header("Content-Type: text/html; charset=utf-8");
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>แก้ไข Permissions - Queue Order System</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-gray-100 p-8">
    <div class="max-w-4xl mx-auto">
        <h1 class="text-3xl font-bold mb-8">🔧 แก้ไข Permissions อัตโนมัติ</h1>
        
        <div class="bg-white rounded-lg shadow-md p-6 mb-6">
            <h2 class="text-xl font-semibold mb-4">🚀 แก้ไขปัญหาอัตโนมัติ</h2>
            <p class="text-gray-600 mb-4">คลิกปุ่มด้านล่างเพื่อแก้ไขปัญหา permissions ทั่วไป:</p>
            
            <?php
            $baseDir = __DIR__;
            $soundsDir = $baseDir . '/sounds';
            $results = [];
            
            if (isset($_POST['fix_permissions'])) {
                // 1. สร้างโฟลเดอร์ sounds
                if (!is_dir($soundsDir)) {
                    if (mkdir($soundsDir, 0755, true)) {
                        $results[] = "✅ สร้างโฟลเดอร์ sounds สำเร็จ";
                    } else {
                        $results[] = "❌ ไม่สามารถสร้างโฟลเดอร์ sounds ได้";
                    }
                } else {
                    $results[] = "✅ โฟลเดอร์ sounds มีอยู่แล้ว";
                }
                
                // 2. ลอง chmod หลายระดับ
                $chmodResults = [];
                $permissions = [0755, 0775, 0777];
                
                foreach ($permissions as $perm) {
                    if (chmod($soundsDir, $perm)) {
                        $chmodResults[] = "✅ chmod " . decoct($perm) . " สำเร็จ";
                        if (is_writable($soundsDir)) {
                            $chmodResults[] = "✅ สามารถเขียนไฟล์ได้แล้ว!";
                            break;
                        }
                    } else {
                        $chmodResults[] = "❌ chmod " . decoct($perm) . " ไม่สำเร็จ";
                    }
                }
                $results = array_merge($results, $chmodResults);
                
                // 3. ตรวจสอบ owner
                $owner = function_exists('posix_getpwuid') ? posix_getpwuid(fileowner($soundsDir))['name'] : 'unknown';
                $webUser = function_exists('posix_getpwuid') ? posix_getpwuid(posix_geteuid())['name'] : 'unknown';
                $results[] = "📁 Owner: $owner";
                $results[] = "🌐 Web User: $webUser";
                
                // 4. สร้างไฟล์ทดสอบ
                $testFile = $soundsDir . '/test_' . time() . '.txt';
                if (file_put_contents($testFile, 'test')) {
                    $results[] = "✅ สามารถสร้างไฟล์ทดสอบได้";
                    unlink($testFile);
                    $results[] = "✅ ลบไฟล์ทดสอบสำเร็จ";
                } else {
                    $results[] = "❌ ไม่สามารถสร้างไฟล์ทดสอบได้";
                }
                
                // 5. แสดงคำสั่งที่แนะนำ
                $results[] = "📋 คำสั่งที่อาจต้องรัน:";
                $results[] = "<code class='bg-gray-100 px-2 py-1 rounded'>chmod 777 " . htmlspecialchars($soundsDir) . "</code>";
                $results[] = "<code class='bg-gray-100 px-2 py-1 rounded'>chown -R www-data:www-data " . htmlspecialchars(dirname($soundsDir)) . "</code>";
                $results[] = "<code class='bg-gray-100 px-2 py-1 rounded'>chown -R apache:apache " . htmlspecialchars(dirname($soundsDir)) . "</code>";
            }
            
            if (!empty($results)) {
                echo "<div class='space-y-2'>";
                foreach ($results as $result) {
                    echo "<div class='p-2 rounded'>" . $result . "</div>";
                }
                echo "</div>";
            }
            ?>
            
            <form method="POST" class="mt-6">
                <button type="submit" name="fix_permissions" 
                        class="bg-blue-500 hover:bg-blue-600 text-white font-bold py-3 px-6 rounded-lg">
                    🔧 แก้ไข Permissions อัตโนมัติ
                </button>
            </form>
        </div>
        
        <div class="bg-white rounded-lg shadow-md p-6 mb-6">
            <h2 class="text-xl font-semibold mb-4">📊 สถานะปัจจุบัน</h2>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div>
                    <strong>sounds directory:</strong> 
                    <span class="<?= is_dir($soundsDir) ? 'text-green-600' : 'text-red-600' ?>">
                        <?= is_dir($soundsDir) ? 'มีอยู่' : 'ไม่มี' ?>
                    </span>
                </div>
                <div>
                    <strong>writable:</strong> 
                    <span class="<?= is_writable($soundsDir) ? 'text-green-600' : 'text-red-600' ?>">
                        <?= is_writable($soundsDir) ? 'ได้' : 'ไม่ได้' ?>
                    </span>
                </div>
                <div>
                    <strong>permissions:</strong> 
                    <code><?= is_dir($soundsDir) ? substr(sprintf('%o', fileperms($soundsDir)), -4) : 'N/A' ?></code>
                </div>
                <div>
                    <strong>PHP User:</strong> 
                    <code><?= get_current_user() ?></code>
                </div>
            </div>
        </div>
        
        <div class="bg-white rounded-lg shadow-md p-6 mb-6">
            <h2 class="text-xl font-semibold mb-4">🧪 ทดสอบอัปโหลด</h2>
            <form action="api/upload_sound.php" method="POST" enctype="multipart/form-data" class="space-y-4">
                <input type="hidden" name="location_code" value="TEST">
                <div>
                    <label class="block text-sm font-medium mb-2">เลือกไฟล์เสียง:</label>
                    <input type="file" name="sound_file" accept="audio/*" required 
                           class="w-full p-2 border rounded-lg">
                </div>
                <button type="submit" 
                        class="bg-green-500 hover:bg-green-600 text-white font-bold py-2 px-4 rounded-lg">
                    ทดสอบอัปโหลด
                </button>
            </form>
        </div>
        
        <div class="bg-blue-50 border border-blue-200 rounded-lg p-6">
            <h2 class="text-xl font-semibold text-blue-800 mb-2">💡 เคล็ดลับ</h2>
            <ul class="text-blue-700 space-y-1">
                <li>• ถ้ายังไม่ได้ ลองรันคำสั่ง chmod 777 ผ่าน SSH</li>
                <li>• ตรวจสอบว่า web server มีสิทธิ์เขียนใน parent directory</li>
                <li>• บน CentOS/RHEL อาจต้องตั้งค่า SELinux</li>
                <li>• บน shared hosting ให้ติดต่อ hosting provider</li>
            </ul>
        </div>
        
        <div class="mt-8 text-center space-x-4">
            <a href="setup_permissions.php" class="bg-gray-500 hover:bg-gray-600 text-white font-bold py-2 px-6 rounded-lg">
                ตรวจสอบ Permissions
            </a>
            <a href="sound_admin.html" class="bg-purple-500 hover:bg-purple-600 text-white font-bold py-2 px-6 rounded-lg">
                จัดการเสียง
            </a>
            <a href="index.html" class="bg-blue-500 hover:bg-blue-600 text-white font-bold py-2 px-6 rounded-lg">
                หน้าหลัก
            </a>
        </div>
    </div>
</body>
</html>
