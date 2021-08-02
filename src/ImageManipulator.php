<?php

namespace PhotoserverSync;

use Aws\S3\S3Client;
use PhotoserverSync\DBConnection;
use Imagine\Gd\Imagine;
use Imagine\Image\Box;
use Imagine\Image\Metadata\ExifMetadataReader;
use PhotoserverSync\SyncFilesToAWS;
use PhotoserverSync\Config\EnvironmentVariables;
use PHPMailer\PHPMailer\PHPMailer;
use GuzzleHttp\Client;
use GuzzleHttp\Pool;
use GuzzleHttp\Psr7\Request;
use Imagine\Image\ImageInterface;

class ImageManipulator
{
  /**
   * @var Imagine
   */
  private $imagine;

  /**
   * @var string
   */
  private $log_file;

  private $client;

  private $config;

  private $mail_host;

  private $mail_username;

  private $mail_password;

  private $mail_recipient_email;

  private $mail_recipient_name;

  private $files_directory;

  private $aws_client;

  private $aws_key;

  private $aws_secret;

  private $aws_bucket_name;

  private $aws_region;

  private $db;

  public function __construct()
  {
    $this->imagine = new Imagine();
    $this->config = new EnvironmentVariables();
    $this->imagine->setMetadataReader(new ExifMetadataReader());
    $this->log_file = fopen(dirname(realpath("."), 1) . DIRECTORY_SEPARATOR ."logs". DIRECTORY_SEPARATOR ."photoserver-sync.log", "w") or die("Unable to open file");
    $this->s3client = new SyncFilesToAWS();
    $this->mail_host = $this->config->getMailHost();
    $this->mail_username = $this->config->getMailUsername();
    $this->mail_password = $this->config->getMailPassword();
    $this->mail_recipient_email = $this->config->getMailRecipientEmail();
    $this->mail_recipient_name = $this->config->getMailRecipientName();
    $this->images = [];
    $this->persons = [];
    $this->files_directory = '/volume/Nextcloud/sneek/files';
    $this->nextcloud_username = $this->config->getNextcloudUsername();
    $this->nextcloud_password = $this->config->getNextcloudPassword();
    $this->client = new Client([
        'base_uri' => 'https://nextcloud.vipenmahay.com/index.php/apps/facerecognition/',
        'auth' => [$this->nextcloud_username, $this->nextcloud_password]
    ]);

    $this->aws_key = $this->config->getAWSKey();
    $this->aws_secret = $this->config->getAWSSecret();
    $this->aws_bucket_name = $this->config->getAWSBucketName();
    $this->aws_region = $this->config->getAWSRegion();

    $this->aws_client = new S3Client([
        'version' => 'latest',
        'region' => $this->aws_region,
        'credentials' => [
            'key'    => $this->aws_key,
            'secret' => $this->aws_secret,
        ]
    ]);

    $this->db = new DBConnection();

    $this->createTable();
    $this->getResults();
  }

  private function createTable()
  {
    $params = [
      'filename' => 'TEXT NOT NULL',
      'date' => 'TEXT',
      'time' => 'TEXT',
      'datetime' => 'TEXT NOT NULL',
      'latitude' => 'TEXT',
      'longitude' => 'TEXT',
      'device' => 'VARCHAR(50)',
      'aperture' => 'VARCHAR(6)',
      'exposure' => 'VARCHAR(6)',
      'iso' => 'VARCHAR(6)',
      'focal_length' => 'VARCHAR(6)',
      'filesize' => 'VARCHAR(50)',
      'dimensions' => 'VARCHAR(20)',
      'orientation' => 'VARCHAR(2)',
      'person' => 'VARCHAR(255)'
    ];

    $this->db->createTable('image_data', $params);
  }

  private function getAWSBucketList()
  {
    $iterator = $this->aws_client->getIterator('ListObjects', array(
      'Bucket' => $this->aws_bucket_name,
    ));

    return $iterator;
  }

  /**
   * REST API call to get list of people's names
   */
  private function getPersons()
  {
      $res = $this->client->get('persons');

      if ($res->getStatusCode() !== 200) {
          return;
      }

      $persons = json_decode($res->getBody());

      $persons = $persons->persons;

      return $persons;
  }

  /**
   * REST API call to get images associated with a person
   */
  private function getResults()
  {
      $persons = $this->getPersons();
      $imageResults = [];

      $requests = function ($persons) {
          foreach($persons as $person){
              yield new Request('GET', "person/" . $person->name);
          }
      };

      $pool = new Pool($this->client, $requests($persons), [
        'concurrency' => 5,
        'fulfilled' => function ($response, $index) use (&$imageResults) {
            $data = json_decode($response->getBody()->getContents(), true);
            array_push($imageResults, $data);

          },
          'rejected' => function ($reason, $index) {
            // this is delivered each failed request
            echo "rejected";
          },
      ]);
      // Initiate the transfers and create a promise
      $promise = $pool->promise();

      // Force the pool of requests to complete.
      $promise->wait();

      $formattedArray = [];

      // format array into something easier to manipulate
      foreach ($imageResults as $person) {
        $tmp = [];
        $formattedArray[$person['name']] = [
          'thumbUrl' => $person['thumbUrl']
        ];
        foreach ($person['images'] as $image) {
          $image['fileUrl'] = rawurldecode(substr($image['fileUrl'], strpos($image['fileUrl'], 'scrollto=') + strlen('scrollto=')));
          array_push($tmp, $image['fileUrl']);
        }
        $formattedArray[$person['name']]['images'] = json_encode($tmp);
      }

      $this->persons = $formattedArray;

      // find all images from root image directory
      $listOfImages = $this->findAllImages($this->files_directory);
      if ($listOfImages) {
          $list = $this->resizeAllImages($listOfImages);
      }
  }

  /**
   * Resizes given images to custom size
   *
   * @param int $width
   * @param int $height
   *
   * @throws InvalidArgumentException
   * @throws Exception
   */
  public function resizeAllImages($images, $width = 200, $height = 200)
  {
    $this->writeToLog("Beginning optimizer", false, true);
    $time_start = microtime(true);

    $start = date("d F Y H:i:s");
    $total = 0;
    $successful = 0;
    $failed = 0;
    $end = null;
    $bucket_images = iterator_to_array($this->getAWSBucketList());

    try {
      $returnedList = [];

      foreach ($images as $key => $path) {
        $continue = false;

        // resize image
        $ext = pathinfo($path, PATHINFO_EXTENSION);
        $file = pathinfo($path, PATHINFO_FILENAME);
        $dir = pathinfo($path, PATHINFO_DIRNAME);

        $rotate = 0;

        foreach ($bucket_images as $object) {
          $key = $object['Key'];

          if ($key === $file . "." . $ext) { // break if file already exists in bucket
            $continue = true;
            break;
          }
      }

      if ($continue) {
        continue;
      }

      $total++;

        // specify filepath we want for the resized images
        $thumbnailPath = $dir . DIRECTORY_SEPARATOR . '_thumb_' . $file . '.' . $ext;
        $mediumPath = $dir . DIRECTORY_SEPARATOR . '_medium_' . $file . '.' . $ext;

        $metadata = $this->getMetadata($path);

        // append person/s to metadata
        foreach ($this->persons as $key => $person) {
          if (strpos($person['images'], $file . '.' . $ext) !== false) {
            if (empty($metadata['person'])) {
              $metadata['person'] = $key;
            } else {
              $metadata['person'] .= ' ' . $key;
            }
          }
        }

        if ($metadata['person']) {
          $this->db->updateValues('table_data', ['person' => $metadata['person']], ['datetime' => $metadata['datetime'], 'filesize' => $metadata['filesize']]);
        }

        if (empty($metadata['filename'])) {
          $metadata['filename'] = $file . '.' . $ext;
        }

        // need to check for orientation as the resize will incorrectly rotate image
        if (!empty($metadata['orientation'])) {
          switch ($metadata['orientation']) {
              case 3:
                $rotate = 180;
                break;
              case 6:
                $rotate = 90;
                break;
              case 8:
                $rotate = -90;
                break;
              default:
                $rotate = 0;
                break;
          }
        }

        $this->writeToLog("Converting image " . $file . '...');
        $this->imagine->open($path)
          ->thumbnail(new Box($width, $height), ImageInterface::THUMBNAIL_INSET) // don't make a clone (preserve memory)
          ->rotate($rotate)
          ->save($thumbnailPath);
        $this->imagine->open($path)
          ->thumbnail(new Box(500, 500), ImageInterface::THUMBNAIL_INSET)
          ->rotate($rotate)
          ->save($mediumPath);

        array_push($returnedList, $path, $thumbnailPath, $mediumPath);

        echo "done\n";
        fwrite($this->log_file, "done\n");

        $this->s3client->uploadToS3($path, ($file . '.' . $ext), $metadata);
        $this->s3client->uploadToS3($mediumPath, ('medium/_medium_' . $file . '.' . $ext), $metadata);
        $this->s3client->uploadToS3($thumbnailPath, ('thumbnail/_thumb_' . $file . '.' . $ext), $metadata);

        $successful++;
      }

      $completedImages = $this->findAllImages($this->files_directory);
      foreach ($completedImages as $image) {
        if (strpos($image, '_thumb_') !== false || strpos($image, '_medium_') !== false) {
          unlink($image);
        }
      }

      $time_end = microtime(true);
      $end = ($time_end - $time_start)/60;
      $end = number_format((float)$end, 2, '.', '');
      echo "Total Execution Time: ".$end." Mins\n";

      // $this->sendEmail($start, $total, $successful, $failed, $end);

      return $returnedList;

    } catch (\InvalidArgumentException $err) {
      $failed++;
      $this->writeToLog("Something went wrong: " . $err, true);
    } catch (\Exception $err) {
      $failed++;
      $this->writeToLog("Something went wrong: " . $err, true);
    }

    fclose($this->log_file);
  }

  /**
   * Reads individual files' metadata
   */
  private function getMetadata($image)
  {
    try {
      $file = pathinfo($image, PATHINFO_FILENAME);

      $this->writeToLog("Reading metadata from image " . $file . '...');
      $path = $this->imagine->open($image);
      $metadata = $path->metadata()->toArray();
      $date = '';
      $time = '';
      if ((array_key_exists('exif.DateTimeOriginal', $metadata) && $metadata['exif.DateTimeOriginal'] !== '')) {
        $strtotime = strtotime($metadata['exif.DateTimeOriginal']);
        $date = date('j F Y', $strtotime);
        $time = date('H:i:s', $strtotime);
      } else if (array_key_exists('file.FileDateTime', $metadata)) {
        $date = date('j F Y', $metadata['file.FileDateTime']);
        $time = date('H:i:s', $metadata['file.FileDateTime']);
      } else {
        $date = date('j F Y');
        $time = date('H:i:s');
      }

      $size = $path->getSize();
      $width = $size->getWidth();
      $height = $size->getHeight();

      $datetime = strtotime($date.$time);

      $metadata =  [
        'date' => $date,
        'time' => $time,
        'datetime' => $datetime,
        'latitude' => array_key_exists('gps.GPSLatitude', $metadata) ? $this->convertGPS($metadata['gps.GPSLatitude'], $metadata['gps.GPSLatitudeRef']) : '',
        'longitude' => array_key_exists('gps.GPSLongitude', $metadata) ? $this->convertGPS($metadata['gps.GPSLongitude'], $metadata['gps.GPSLongitudeRef']) : '',
        'device' => array_key_exists('ifd0.Model', $metadata) ? $metadata['ifd0.Model'] : '',
        'aperture' => array_key_exists('computed.ApertureFNumber', $metadata) ? $metadata['computed.ApertureFNumber'] : '',
        'exposure' =>  array_key_exists('exif.ExposureTime', $metadata) ? $metadata['exif.ExposureTime'] : '',
        'iso' => array_key_exists('exif.ISOSpeedRatings', $metadata) ? $metadata['exif.ISOSpeedRatings'] : '',
        'focal_length' => array_key_exists('exif.FocalLength', $metadata) ? $metadata['exif.FocalLength'] : '',
        'filesize' => array_key_exists('file.FileSize', $metadata) ? $metadata['file.FileSize'] : '',
        'filename' => array_key_exists('file.FileName', $metadata) ? $metadata['file.FileName'] : '',
        // 'dimensions' => array_key_exists('computed.Width', $metadata) ? $metadata['computed.Width'] . 'x' . $metadata['computed.Height'] : '',
        'dimensions' => $width .  'x' . $height,
        'orientation' => array_key_exists('ifd0.Orientation', $metadata) ? $metadata['ifd0.Orientation'] : ''
      ];

      $this->db->insertValues('table_data', $metadata);

      echo "done\n";
      fwrite($this->log_file, "done\n");

      return $metadata;

    } catch (\InvalidArgumentException $err) {
      $this->writeToLog("Something went wrong: " . $err, true);
    } catch (\Exception $err) {
      $this->writeToLog("Something went wrong: " . $err, true);
    }
  }

  private function convertFocalLength($value)
  {
    if ($value) {
      $value = explode('/', $value);
      $a = intval($value[0]);
      $b = intval($value[1]);

      return round($a / $b) . 'mm';
    }
  }

  /**
   * Convert GPS coords to something more translatable
   */
  private function convertGPS($coordinate, $hemisphere) {
    if (is_string($coordinate)) {
      $coordinate = array_map("trim", explode(",", $coordinate));
    }
    for ($i = 0; $i < 3; $i++) {
      $part = explode('/', $coordinate[$i]);
      if (count($part) == 1) {
        $coordinate[$i] = $part[0];
      } else if (count($part) == 2) {
        $coordinate[$i] = floatval($part[0])/floatval($part[1]);
      } else {
        $coordinate[$i] = 0;
      }
    }
    list($degrees, $minutes, $seconds) = $coordinate;
    $sign = ($hemisphere == 'W' || $hemisphere == 'S') ? -1 : 1;
    return $sign * ($degrees + $minutes/60 + $seconds/3600);
  }

  /**
   * Finds all images within a given directory recursively
   */
  public function findAllImages($dir)
  {
    try {
      if (!is_dir($dir)) {
        $this->writeToLog("Error reading from " . $dir . " directory. Does it exist?", false, true);
        throw new \Exception("Error reading from " . $dir . " directory. Does it exist?");
      }
      $this->writeToLog("Finding all images", false, true);
      $files = scandir($dir);

      foreach ($files as $key => $value) {
        $path = realpath($dir . DIRECTORY_SEPARATOR . $value);

        if (strpos($path, 'Synced') !== false) {
          continue;
        }

        if (!is_dir($path)) {
          $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
          if (in_array($ext, ['jpg', 'png', 'jpeg'])) {
            array_push($this->images, $path);
          }
        } else if ($value != '.' && $value != "..") {
          $this->findAllImages($path);
        }
      }
      return $this->images;
    } catch (\Exception $err) {
      echo $err;
    }
  }

  /**
   * Adds messaging to a log file
   */
  private function writeToLog($message, $prependNewLine = false, $appendNewLine = false)
  {
    if ($prependNewLine) {
      $text = "\n" ."[" . date("d/m/Y g:ia",strtotime('now')) . "] " . $message;
    }

    $text = "[" . date("d/m/Y g:ia",strtotime('now')) . "] " . $message;

    if ($appendNewLine) {
      $text = $text . "\n";
    }

    echo $text;
    fwrite($this->log_file, $text);
  }

  /**
   * Moves files from one directory to another
   */
  public function moveFiles($oldPath, $newPath)
  {
    $file = pathinfo($oldPath, PATHINFO_FILENAME);
    $ext = pathinfo($oldPath, PATHINFO_EXTENSION);
    $newPath = realpath($newPath);
    $newPath = $newPath . DIRECTORY_SEPARATOR . $file . '.' . $ext;

    if (file_exists($oldPath)) {
      if (!rename($oldPath, $newPath)) {
        if (copy ($oldPath, $newPath)) {
          unlink($oldPath);
          $this->writeToLog("Successfully moved " .  $file, false, true);
          return true;
        } else {
          $this->writeToLog("Couldn't move " .  $file, true, true);
          return false;
        }
      } else {
        $this->writeToLog("Successfully moved " .  $file, false, true);
        return true;
      }
    }
  }

  /**
   * Emails a brief summary of the job
   */
  private function sendEmail($start, $total, $successful, $failed, $end)
  {
    $mail = new PHPMailer;
    $mail->isSMTP();
    $mail->isHTML(true);
    $mail->SMTPDebug = 2;
    $mail->Host = $this->mail_host;
    $mail->Port = 587;
    $mail->SMTPAuth = true;
    $mail->Username = $this->mail_username;
    $mail->Password = $this->mail_password;
    $mail->setFrom($this->mail_username, 'PhotoServer Sync');
    $mail->addReplyTo($this->mail_username, 'PhotoServer Sync');
    $mail->addAddress($this->mail_recipient_email, $this->mail_recipient_name);
    $mail->Subject = 'PhotoServer Sync Uploader';
    $mail->Body = '<h1>PhotoServer Sync uploader ran</h1>
      <p>The script was run on ' . $start . '</p></br>
      <p><strong>Result</strong></p>
      <table>
        <tbody>
          <tr>
              <td>Total number of images:</td>
              <td>' . $total . '</td>
          </tr>
          <tr>
            <td>No. of images successfully processed:</td>
            <td>' . $successful . '</td>
          </tr>
          <tr>
            <td>No. of images failed:</td>
            <td>' . $failed  . '</td>
          </tr>
          <tr>
            <td>Total execution time:</td>
            <td>' . $end . ' minutes</td>
          </tr>
        </tbody>
      </table>';
    if (!$mail->send()) {
        echo 'Mailer Error: ' . $mail->ErrorInfo;
    } else {
        echo 'The email message was sent.';
    }
  }
}