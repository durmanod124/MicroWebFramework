<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/vendor/autoload.php';

use Silex\Application;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

$app = new Application();
$app['debug'] = true;

// Define the uploads directory
define('UPLOADS_DIR', __DIR__ . '/uploads');

// Ensure the uploads directory exists
if (!file_exists(UPLOADS_DIR)) {
    mkdir(UPLOADS_DIR, 0777, true); // Ensure the directory is created and accessible
}

// Handle 1 BY 1 FILE uploads
$app->post('/upload-1-by-1', function (Request $request) use ($app) {
    $file = $request->files->get('file');

    if (!$file) {
        return new Response('No file uploaded.', 400);
    }

    if (!in_array($file->getMimeType(), ['image/jpeg', 'image/png', 'image/gif'])) {
        return new Response('Invalid file type. Only images of type "png", "jpeg" and "gif" are allowed. Please upload your file again!', 400);
    }

    try {
        $filename = uniqid() . '.' . $file->guessExtension();
        $file->move(UPLOADS_DIR, $filename);
    } catch (Exception $e) {
        return new Response('Failed to save the file. Please try again later!', 500);
    }

    $baseUrl = $request->getSchemeAndHttpHost();
    return $app->json(['url' => $baseUrl . '/uploads/' . $filename]);
});

// Handle ZIP file uploads containing images
$app->post('/upload-zip', function (Request $request) use ($app) {
    $file = $request->files->get('file');

    if (!$file) {
        return new Response('No file uploaded.', 400);
    }

    if ($file->getMimeType() !== 'application/zip') {
        return new Response('Invalid file type. Please upload a ZIP file containing images.', 400);
    }

    try {
        $zip = new ZipArchive();
        $zipFilePath = UPLOADS_DIR . '/' . uniqid() . '.zip';
        $file->move(UPLOADS_DIR, basename($zipFilePath));

        if ($zip->open($zipFilePath) === TRUE) {
            $imageUrls = [];
            
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $zipFile = $zip->getNameIndex($i);
                $tempFile = tempnam(sys_get_temp_dir(), 'img_');
                $zip->extractTo(sys_get_temp_dir(), $zipFile);

                $filePath = sys_get_temp_dir() . '/' . $zipFile;
                $fileMimeType = mime_content_type($filePath);

                if (in_array($fileMimeType, ['image/jpeg', 'image/png', 'image/gif'])) {
                    $filename = uniqid() . '.' . pathinfo($zipFile, PATHINFO_EXTENSION);
                    $newFilePath = UPLOADS_DIR . '/' . $filename;
                    rename($filePath, $newFilePath);
                    $imageUrls[] = '/uploads/' . $filename;
                }
            }

            $zip->close();

            if (count($imageUrls) > 0) {
                $baseUrl = $request->getSchemeAndHttpHost();
                $imageUrls = array_map(fn($url) => $baseUrl . $url, $imageUrls);
                return $app->json(['urls' => $imageUrls]);
            } else {
                return new Response('No valid images found in the ZIP file.', 400);
            }
        } else {
            throw new Exception('Failed to open the ZIP file. Please try again later.');
        }
    } catch (Exception $e) {
        error_log('Error processing ZIP file: ' . $e->getMessage());
        return new Response('An error occurred while processing the ZIP file: ' . $e->getMessage(), 500);
    }
});

// Handle frontend HTML, CSS, and JS
$app->get('/', function () use ($app) {
    return new Response(<<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Image Upload</title>
    <style>
        #popup {
            display: none;
            position: fixed;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            background: #f8d7da;
            color: white;
            padding: 20px;
            border: 1px solid;
            border-radius: 5px;
            border-color: #f8d7da;
            z-index: 1000;
        }

        #popup button {
            background: #842029;
            color: #fff;
            border: none;
            padding: 5px 10px;
            cursor: pointer;
            border-radius: 3px;
        }
    </style>
</head>
<body>
    <h1>Upload an Image</h1>
    <form id="uploadForm" action="/upload-1-by-1" method="post" enctype="multipart/form-data">
        <label for="file">Select an image:</label>
        <input type="file" name="file" id="file" required>
        <button type="submit">Upload</button>
    </form>

    <div id="popup">
        <p id="popupMessage"></p>
        <button onclick="closePopup()">Close</button>
    </div>

    <script>
        const form = document.getElementById('uploadForm');
        const popup = document.getElementById('popup');
        const popupMessage = document.getElementById('popupMessage');
    
        form.addEventListener('submit', async function (e) {
            e.preventDefault();
    
            const formData = new FormData(form);
            const file = formData.get('file');
            const isZipFile = file && file.name.endsWith('.zip');
            const uploadUrl = isZipFile ? '/upload-zip' : '/upload-1-by-1'; 
    
            try {
                const response = await fetch(uploadUrl, {
                    method: 'POST',
                    body: formData
                });
    
                if (!response.ok) {
                    const errorMessage = await response.text();
                    showPopup(errorMessage, "#FFCDD2", "#D32F2F");
                } else {
                    const result = await response.json();
                    let message = '';
                    if (isZipFile) {
                        const imageLinks = result.urls.map(url => `${url}`).join("\n");
                        message = `Images uploaded successfully! View them at: \n${imageLinks}`;
                    } else {
                        message = `Image uploaded successfully! View it at: <a href="${result.url}" target="_blank">${result.url}</a>`;
                    }
                    showPopup(message, "#E8F5E9", "#388E3C");
                    form.reset();
                }
            } catch (error) {
                showPopup(error, "#FFCDD2", "#D32F2F");
            }
        });
    
        function showPopup(message, bgColor, textColor) {
            popupMessage.textContent = message;
            popup.style.display = 'block';
            popup.style.backgroundColor = bgColor; 
            popup.style.borderColor = bgColor
            popup.style.color = textColor;
        }
    
        function closePopup() {
            popup.style.display = 'none';
        }
    </script>
</body>
</html>
HTML
    );
});

// Start the application
$app->run();
?>
