<?php
// setup_permissions.php - หน้าสำหรับตรวจสอบและตั้งค่า permissions

header("Content-Type: text/html; charset=utf-8");
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ตั้งค่า Permissions - Queue Order System</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-gray-100 p-8">
    <div class="max-w-4xl mx-auto">
        <h1 class="text-3xl font-bold mb-8">🔧 ตั้งค่า Permissions สำหรับ Queue Order System</h1>
        
        <div class="bg-white rounded-lg shadow-md p-6 mb-6">
            <h2 class="text-xl font-semibold mb-4">📁 ตรวจสอบและสร้างโฟลเดอร์ที่จำเป็น</h2>
            <?php
            $baseDir = __DIR__;
            $requiredDirs = ['sounds'];
            $allGood = true;
            
            foreach ($requiredDirs as $dir) {
                $dirPath = $baseDir . '/' . $dir;
                echo "<div class='mb-4 p-4 border rounded-lg'>";
                echo "<h3 class='font-semibold mb-2'>📂 $dir</h3>";
                
                if (!is_dir($dirPath)) {
                    echo "<p class='text-orange-600 mb-2'>⚠️ ไม่พบโฟลเดอร์ กำลังสร้าง...</p>";
                    if (mkdir($dirPath, 0755, true)) {
                        echo "<p class='text-green-600'>✅ สร้างโฟลเดอร์สำเร็จ</p>";
                    } else {
                        echo "<p class='text-red-600'>❌ ไม่สามารถสร้างโฟลเดอร์ได้</p>";
                        $allGood = false;
                    }
                } else {
                    echo "<p class='text-green-600'>✅ พบโฟลเดอร์แล้ว</p>";
                }
                
                if (is_dir($dirPath)) {
                    if (is_writable($dirPath)) {
                        echo "<p class='text-green-600'>✅ สามารถเขียนไฟล์ได้</p>";
                    } else {
                        echo "<p class='text-red-600'>❌ ไม่สามารถเขียนไฟล์ได้</p>";
                        echo "<p class='text-sm text-gray-600 mt-2'>คำสั่งที่ต้องรัน: <code class='bg-gray-100 px-2 py-1 rounded'>chmod 755 " . htmlspecialchars($dirPath) . "</code></p>";
                        $allGood = false;
                    }
                    
                    echo "<p class='text-sm text-gray-600'>Path: " . htmlspecialchars($dirPath) . "</p>";
                    echo "<p class='text-sm text-gray-600'>Permissions: " . substr(sprintf('%o', fileperms($dirPath)), -4) . "</p>";
                }
                
                echo "</div>";
            }
            ?>
        </div>
        
        <div class="bg-white rounded-lg shadow-md p-6 mb-6">
            <h2 class="text-xl font-semibold mb-4">⚙️ ข้อมูล PHP Configuration</h2>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div>
                    <strong>upload_max_filesize:</strong> <?= ini_get('upload_max_filesize') ?>
                </div>
                <div>
                    <strong>post_max_size:</strong> <?= ini_get('post_max_size') ?>
                </div>
                <div>
                    <strong>max_execution_time:</strong> <?= ini_get('max_execution_time') ?>s
                </div>
                <div>
                    <strong>memory_limit:</strong> <?= ini_get('memory_limit') ?>
                </div>
                <div>
                    <strong>upload_tmp_dir:</strong> <?= ini_get('upload_tmp_dir') ?: 'System default' ?>
                </div>
                <div>
                    <strong>file_uploads:</strong> <?= ini_get('file_uploads') ? 'On' : 'Off' ?>
                </div>
            </div>
        </div>
        
        <div class="bg-white rounded-lg shadow-md p-6 mb-6">
            <h2 class="text-xl font-semibold mb-4">🧪 ทดสอบการอัปโหลดไฟล์</h2>
            <form action="api/upload_sound.php" method="POST" enctype="multipart/form-data" class="space-y-4">
                <input type="hidden" name="location_code" value="TEST">
                <div>
                    <label class="block text-sm font-medium mb-2">เลือกไฟล์เสียง (ทดสอบ):</label>
                    <input type="file" name="sound_file" accept="audio/*" required 
                           class="w-full p-2 border rounded-lg">
                </div>
                <button type="submit" 
                        class="bg-blue-500 hover:bg-blue-600 text-white font-bold py-2 px-4 rounded-lg">
                    ทดสอบอัปโหลด
                </button>
            </form>
        </div>
        
        <?php if ($allGood): ?>
        <div class="bg-green-50 border border-green-200 rounded-lg p-6">
            <h2 class="text-xl font-semibold text-green-800 mb-2">✅ พร้อมใช้งาน!</h2>
            <p class="text-green-700">ระบบพร้อมใช้งานแล้ว คุณสามารถไปที่หน้า <a href="index.html" class="text-blue-600 underline">หลัก</a> หรือ <a href="sound_admin.html" class="text-blue-600 underline">จัดการเสียง</a></p>
        </div>
        <?php else: ?>
        <div class="bg-red-50 border border-red-200 rounded-lg p-6">
            <h2 class="text-xl font-semibold text-red-800 mb-2">⚠️ ต้องแก้ไขปัญหาก่อน</h2>
            <p class="text-red-700">กรุณาแก้ไขปัญหา permissions ก่อนจึงจะใช้งานได้</p>
        </div>
        <?php endif; ?>
        
        <div class="mt-8 text-center">
            <a href="index.html" class="bg-gray-500 hover:bg-gray-600 text-white font-bold py-2 px-6 rounded-lg">
                กลับหน้าหลัก
            </a>
        </div>
    </div>
</body>
</html>
