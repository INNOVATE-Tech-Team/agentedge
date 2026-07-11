<?php
$file = '/var/www/html/university_lesson.php';
$lines = file($file);
$warn = "\xe2\x9a\xa0";
$newBlock = '      <?php if ($embedUrl): ?>
      <div class="video-wrap" style="padding-top:56.25%;position:relative;background:#000;border-radius:10px;overflow:hidden;margin-bottom:20px">
        <iframe src="<?= htmlspecialchars($embedUrl) ?>" style="position:absolute;top:0;left:0;width:100%;height:100%;border:0" allowfullscreen allow="autoplay; fullscreen; picture-in-picture" onload="scheduleEmbedComplete()"></iframe>
      </div>
      <?php elseif ($lesson[\'file_key\']): ?>
      <div class="video-wrap">
        <video id="lesson-video" controls preload="metadata" onended="onVideoEnd()">
          <source src="api/uni_download.php?id=<?= $lessonId ?>" type="video/mp4">
          Your browser does not support video playback.
        </video>
      </div>
      <?php else: ?>
      <div class="doc-wrap" style="background:#fff3cd;border-color:#ffc107">
        <div class="doc-icon">' . $warn . '</div>
        <div class="doc-title">Video not uploaded yet</div>
      </div>
      <?php endif; ?>
';
array_splice($lines, 154, 13, [$newBlock]);
file_put_contents($file, implode('', $lines));
echo "Done - " . count($lines) . " lines written\n";
