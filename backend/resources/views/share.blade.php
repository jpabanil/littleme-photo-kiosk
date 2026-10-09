<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>Your Little Me photo</title>
    <link rel="stylesheet" href="/share.css">
</head>
<body>
    <main class="sheet">
        <p class="brand">Little Me</p>
        <h1>Your photo is ready</h1>
        <p class="lede">Save it to your phone, or send it to someone you came with.</p>
        <img src="{{ $imageUrl }}" alt="Generated childhood portrait">
        <div class="actions">
            <a class="primary" href="{{ $downloadUrl }}">Download</a>
            <button type="button" id="share" data-url="{{ $shareUrl }}">Share</button>
        </div>
        <p id="copied" class="copied" hidden>Link copied</p>
    </main>
    <script src="/share.js"></script>
</body>
</html>
