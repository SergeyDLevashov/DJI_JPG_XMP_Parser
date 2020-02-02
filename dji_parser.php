<!DOCTYPE html>
<html lang="en">
  <head>
    <meta charset="utf-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="description" content="">
    <meta name="author" content="">
    <link rel="icon" href="favicon.ico">

    <title>DJI Photos parsing to KML</title>

  </head>

  <body>
  
<h1>DJI JPG EXIF(XMP) parser with KML output</h1>
<p>Парсинг директории с фотографиями с дронов DJI для создания карты сделанных фотографий. 
 Для каждой фотографии создается треугольная фигура с вершиной в точке съемки и основанием, указывающим на 
 направление съемки. Размер фигуры пропорционален высоте съемки. Также из вершины фигуры через центр основания 
 рисуется линия с длинной, зависящей от угла наклона фотографии. Минимальная длинна будет у фотографии в надир. </p>
<br>

<?php

// Широта Москвы - 56 градусов
// Длинна 1 градуса вдоль долготы - 111км
// Один километр = 0.54 минуты или 0.0093 градуса
$meter_lat=0.0093/1000; //вдоль долготы широта

// Длинна 1 градуса вдоль широты зависит от косинуса широты, cos(56 граудсов)=0.5591 значит 0.5591*111=62км
// Один километр вдоль широты на широте Москвы = 0.96 минуты или 0.016 градуса
$meter_lon=0.016/1000; //вдоль широты долгота

// относительная директория, в которой будут обработаны все файлы с расширением *.JPG
$InputDir=".\*.JPG";

// Коэфф масштаба, для отрисовки фигур, чтобы не захламлять карту
$dji_scale=0.3; 

function getXmpData($filename, $chunk_size = 1024)
{
	//https://stackoverflow.com/questions/1578169/how-can-i-read-xmp-data-from-a-jpg-with-php/26976859#26976859
	if (!is_int($chunk_size)) {
		throw new RuntimeException('Expected integer value for argument #2 (chunkSize)');
	}

	if ($chunk_size < 12) {
		throw new RuntimeException('Chunk size cannot be less than 12 argument #2 (chunkSize)');
	}

	if (($file_pointer = fopen($filename, 'rb')) === FALSE) {
		throw new RuntimeException('Could not open file for reading');
	}

	$tag = '<x:xmpmeta';
	$buffer = false;

	// find open tag
	while ($buffer === false && ($chunk = fread($file_pointer, $chunk_size)) !== false) {
		if(strlen($chunk) <= 10) {
			break;
		}
		if(($position = strpos($chunk, $tag)) === false) {
			// if open tag not found, back up just in case the open tag is on the split.
			fseek($file_pointer, -10, SEEK_CUR);
		} else {
			$buffer = substr($chunk, $position);
		}
	}

	if($buffer === false) {
		fclose($file_pointer);
		return false;
	}

	$tag = '</x:xmpmeta>';
	$offset = 0;
	while (($position = strpos($buffer, $tag, $offset)) === false && ($chunk = fread($file_pointer, $chunk_size)) !== FALSE && !empty($chunk)) {
		$offset = strlen($buffer) - 12; // subtract the tag size just in case it's split between chunks.
		$buffer .= $chunk;
	}

	fclose($file_pointer);

	if($position === false) {
		// this would mean the open tag was found, but the close tag was not.  Maybe file corruption?
		throw new RuntimeException('No close tag found.  Possibly corrupted file.');
	} else {
		$buffer = substr($buffer, 0, $position + 12);
	}

	return $buffer;
}



$fp = fopen('data.kml', 'w');
$file='<?xml version="1.0" encoding="UTF-8"?><kml xmlns="http://www.opengis.net/kml/2.2"><Document>';
$file.='<Style id="line1">
      <LineStyle>
        <color>ffab4939</color>
        <width>3.713</width>
      </LineStyle>
      <BalloonStyle>
        <text><![CDATA[<h3>$[name]</h3>]]></text>
      </BalloonStyle>
    </Style>';



 foreach (glob($InputDir) as $dji_file) {
    echo "$dji_file<br>\n";


$p = xml_parser_create();
xml_parse_into_struct($p, getXmpData($dji_file, $chunk_size = 1024), $vals, $index);
xml_parser_free($p);

$dji_yaw=$vals[2]['attributes']['DRONE-DJI:GIMBALYAWDEGREE']; // направление подвеса по YAW
$dji_alt=$vals[2]['attributes']['DRONE-DJI:RELATIVEALTITUDE']; // высота относительно взлета
$dji_pitch=$vals[2]['attributes']['DRONE-DJI:GIMBALPITCHDEGREE']+$vals[2]['attributes']['DRONE-DJI:FLIGHTPITCHDEGREE']; // угол наклона подвеса (учесть угол наклона дрона)
$dji_lat=$vals[2]['attributes']['DRONE-DJI:GPSLATITUDE']; // широта
$dji_lon=$vals[2]['attributes']['DRONE-DJI:GPSLONGITUDE']; // долгота
//echo "<pre>";
//print_r($vals[2]['attributes']);
//echo "</pre>";

//переведем YAW из полярных координат в прямоугольные с длинной отрезка, равной высоте съемки
//две точки расчитаны для указания направления обзора, +/- 25 градусов 
$dji_x1=$dji_alt*sin(deg2rad($dji_yaw-25))*$dji_scale;
$dji_y1=$dji_alt*cos(deg2rad($dji_yaw-25))*$dji_scale;

$dji_x2=$dji_alt*sin(deg2rad($dji_yaw+25))*$dji_scale;
$dji_y2=$dji_alt*cos(deg2rad($dji_yaw+25))*$dji_scale;

//третья точка для линии в направлении съемки
$dji_x3=($dji_alt*2*cos(deg2rad($dji_pitch*-1))+10)*sin(deg2rad($dji_yaw))*$dji_scale; // длинна линии от точки съемки по направлению съемки будет зависеть от
$dji_y3=($dji_alt*2*cos(deg2rad($dji_pitch*-1))+10)*cos(deg2rad($dji_yaw))*$dji_scale; // высоты и угла наклона фотографии, но не меньше 10м (при съемке в надир)

//Добавим прямоугольные координаты к географическим (!) с учетом малого масштаба и 56 параллели
$dji_lon1=$dji_lon+($dji_x1*$meter_lon);
$dji_lat1=$dji_lat+($dji_y1*$meter_lat);
$dji_lon2=$dji_lon+($dji_x2*$meter_lon);
$dji_lat2=$dji_lat+($dji_y2*$meter_lat);
$dji_lon3=$dji_lon+($dji_x3*$meter_lon);
$dji_lat3=$dji_lat+($dji_y3*$meter_lat);

$file.="<Placemark><name>".$dji_file."   ".$dji_alt."m   ".$dji_pitch."°</name><styleUrl>#line1</styleUrl><LineString><tessellate>1</tessellate><coordinates>\n";
$file.=$dji_lon.",".$dji_lat.",".$dji_alt."\n";
$file.=$dji_lon1.",".$dji_lat1.",".$dji_alt."\n";
$file.=$dji_lon2.",".$dji_lat2.",".$dji_alt."\n";
$file.=$dji_lon.",".$dji_lat.",".$dji_alt."\n";
$file.=$dji_lon3.",".$dji_lat3.",0\n";
$file.="</coordinates></LineString></Placemark>\n\n";
}

$file.="</Document>\n</kml>\n";

fwrite($fp, $file);
fclose($fp);
?>

  </body>
</html>
