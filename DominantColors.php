<?php

/**
	* DominantColor
	*
	* MIT License
	* 
	* Copyright (c) 2017 Matthias Planitzer
	* http://www.matthiasplanitzer.de/
	* https://github.com/thisancog/DominantColors/
	*
	* Permission is hereby granted, free of charge, to any person obtaining a copy
	* of this software and associated documentation files (the "Software"), to deal
	* in the Software without restriction, including without limitation the rights
	* to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
	* copies of the Software, and to permit persons to whom the Software is
	* furnished to do so, subject to the following conditions:
	*
	* The above copyright notice and this permission notice shall be included in all
	* copies or substantial portions of the Software.
	*
	* THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
	* IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
	* FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
	* AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
	* LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
	* OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE
	* SOFTWARE.
	*
	*
	*
	* initialize DominantColors with image path and settings,
	* retrieve dominant colors by calling getDominantColors() on object
	* within Wordpress you may also call get_dominant_colors($attachment_id, $settings)
	* for usage within Wordpress, find description at the end of this file
	*
	* @requires PHP GD library
	* 
	* @param $imgPath (string) image to be analyzed, if not given, getDominantColors will result in FALSE
	*				valid file types include bmp, gif, jpeg, png, xbm, xpm, wbmp, webp
	*				the software assumes a correct file extension (required)
	* @param $settings (array) settings (optional), including:
	*			colorsNum (int)		number of colors to retrieve
	*						default: 5
	*			clustersNum (int)	number of clusters to form (more clusters lead to more
	*						precise colors and take longer to compute)
	*						default: 5
	*			similarity (float)	lower threshhold on difference between clusters
	*						(larger threshhold leads to shorter computation time)
	*						default: 0.15
	*			resizeWidth (int)	resize image if width is larger than given value
	*						(cuts computation time)
	* 						default: 100
	*			resizeHeight (int)	resize image if height is larger than given value
	*						(cuts computation time)
	* 						default: 100
	*			verbose (bool)		return information on clusters for each iteration
	*						default: false
	*
	* @return: not verbose:	returns associative array with 'foundColors' containing found colors,
	*			each a hex color string with leading #, ordered by frequency
	*          verbose:	in addition, returns information on clusters for each iteration
	* @return:		false on error
	*
	*
	* example:	$colors = new DominantColors('example.jpg', array('colorsNum' => 3, 'clustersNum' => 7, 'verbose' => true));
	*		$dominantColors = $colors->getDominantColors();
	*		if (false !== $dominantColors) {
	*			foreach ($dominantColors['foundColors']) {
	*				...
	*			}
	*		}
	*
**/



class DominantColors {
	private $imgPath;
	private $settings = array(
		'colorsNum' 	=> 5,
		'clustersNum'	=> 5,
		'similarity'	=> 0.15,
		'resizeWidth' 	=> 100,
		'resizeHeight'	=> 100,
		'verbose'		=> false
	);
	private $imgWidth;
	private $imgHeight;
	private $foundColors = false;
	private $verboseResult = array();

	function __construct($imgPath = '', $newSettings = array()) {
		if (empty($imgPath)) {
			return false;
		}

		$this->imgPath = $imgPath;

		// unfortunately no input validation in array_merge()
		foreach ($newSettings as $option => $setting) {
			if ($option == 'similarity' && (is_int($setting) || is_float($setting)) && ($setting > 0)) {
				$this->settings[$option] = $setting;
			} else if ($option == 'verbose' && is_bool($setting)) {
				$this->settings[$option] = $setting;
			} else if (is_int($setting) && ($setting > 0)) {
				$this->settings[$option] = $setting;	
			}
		}

		if ($this->settings['colorsNum'] > $this->settings['clustersNum'])
			$this->settings['colorsNum'] = $this->settings['clustersNum'];
	}

	public function getDominantColors() {
		if (empty($this->imgPath)) {
			return false;
		}

		$pixels = $this->loadImage();
		if (false === $pixels)
			return false;

		$results = $this->kmeans($pixels);

		if (count($results) > 0) {
			$dominantColors = array();

			for ($i = 0; $i < $this->settings['colorsNum']; $i++) {
				$dominantColors[] = $this->RGBtoHEX($results[$i]);
			}

			$foundColors = array('foundColors' => $dominantColors);

			if ($this->settings['verbose']) {
				$this->foundColors = array_merge($foundColors, $this->verboseResult);
			} else {
				$this->foundColors = $foundColors;
			}
		}

		return $this->foundColors;
	}

	private function loadImage() {
		$image = false;
		$extension = pathinfo($this->imgPath, PATHINFO_EXTENSION);
		$pixels = false;

		switch ($extension) {
			case 'bmp':
				$image = @ImageCreateFromBMP($this->imgPath);
				break;
			case 'gif':
				// PHP GD only sources the first frame in animated gifs
				$image = @ImageCreateFromGIF($this->imgPath);
				break;
			case 'jpeg':
				$image = @ImageCreateFromJPEG($this->imgPath);
				break;
			case 'jpg':
				$image = @ImageCreateFromJPEG($this->imgPath);
				break;
			case 'png':
				$image = @ImageCreateFromPNG($this->imgPath);
				break;
			case 'webp':
				$image = @ImageCreateFromWEBP($this->imgPath);
				break;
			case 'wbmp':
				$image = @ImageCreateFromWBMP($this->imgPath);
				break;
			case 'xbm':
				$image = @ImageCreateFromXBM($this->imgPath);
				break;
			case 'xpm':
				$image = @ImageCreateFromXPM($this->imgPath);
				break;
			default:
				break;
		}

		if ($image) {
			// downscale image so we don't have to deal with as many pixels
			// highly encouraged to do so in order to cut computation time with little
			// if any accuracy loss
			$size = getimagesize($this->imgPath);
			$naturalWidth = $newWidth = $size[0];
			$naturalHeight = $newHeight = $size[1];

			if (($naturalWidth > $this->settings['resizeWidth']) || ($naturalHeight > $this->settings['resizeHeight'])) {
				$ratio = ($naturalHeight > 0) ? $naturalWidth / $naturalHeight : 0;

				if ($naturalWidth - $this->settings['resizeWidth'] > $naturalHeight - $this->settings['resizeHeight']) {
					$newWidth = $this->settings['resizeWidth'];
					$newHeight = ($ratio > 0) ? floor($newWidth / $ratio) : 0;
				} else {
					$newHeight = $this->settings['resizeHeight'];
					$newWidth = floor($ratio * $newHeight);
				}

				$image = imagescale($image, $newWidth, $newHeight);
			}

			$this->imgWidth = $newWidth;
			$this->imgHeight = $newHeight;

			$pixels = array();

			// load color values for each pixel into an array of RGB values
			for ($x = 0; $x < $newWidth; $x++) {
				for ($y = 0; $y < $newHeight; $y++) {
					$pixelValue = imagecolorat($image, $x, $y);
					$pixels[] = array(
						$pixelValue >> 16,		// R
						$pixelValue >> 8 & 255,	// G
						$pixelValue & 255		// B
					);
				}
			}
		} 

		return $pixels;
	}


	// K-means++ clustering of pixels within RGB color space

	private function kmeans($pixels) {
		$numPixels = $this->imgWidth * $this->imgHeight;

		// each cluster will be an array consisting of
		// its center (index 0) and an array of all the pixels it contains (index 1)	

		$initalPixel = $pixels[floor(rand(0, $numPixels - 1))];
		$clusters = array(array($initalPixel, array($initalPixel)));
		while (count($clusters) < $this->settings['clustersNum']) {
			$pixelList = array();

			// generate list of pixels and calculate their distance to nearest cluster center

			$cumulativeSquareDistance = 0;

			foreach ($pixels as $pixel) {
				$smallest_distance = 99999999;
				foreach ($clusters as $cluster) {
					$distance = $this->distance($pixel, $cluster[0]);
					if ($distance < $smallest_distance) {
						$smallest_distance = $distance;
					}
				}

				$cumulativeSquareDistance += $smallest_distance * $smallest_distance;
				$pixelList[] = array($cumulativeSquareDistance, $pixel);
			}

			// get random new cluster center from pixel list with their squared
			// distance being a measure of how likely they are picked
			// for faster search, probability is expressed cumulatively while
			// subsequently narrowing down the list by random pick, compare and list reduction


			$randomPick = rand(0, $cumulativeSquareDistance);
			while (count($pixelList) > 1) {
				$randomIndex = rand(0, count($pixelList) - 1);
				if ($pixelList[$randomIndex][0] < $randomPick) {
					$pixelList = array_slice($pixelList, $randomIndex + 1);
				} else if ($pixelList[$randomIndex][0] > $randomPick) {
					$pixelList = array_slice($pixelList, 0, $randomIndex + 1);
				} else {
					$pixelList = array_slice($pixelList, $randomIndex, 1);
				}
			}

			$pixel = $pixelList[0][1];
			$clusters[] = array($pixel, array($pixel)); 
		}

		if ($this->settings['verbose'])
			$this->verboseResult['initialCenters'] = $this->getColorList($clusters);

		$iteration = 0;

		while (true) {
			$pixelLists = array_fill(0, $this->settings['clustersNum'], array());

			// assign each pixel to its closest cluster,
			// whereas closeness is defined by Euclidian distance

			for ($i = 0; $i < $numPixels; $i++) {
				$pixel = $pixels[$i];
				$smallest_distance = 99999999;
				$closestIndex = 0;
				for ($j = 0; $j < $this->settings['clustersNum']; $j++) {
					$distance = $this->distance($pixel, $clusters[$j][0]);
					if ($distance < $smallest_distance) {
						$smallest_distance = $distance;
						$closestIndex = $j;
					}
				}
				$pixelLists[$closestIndex][] = $pixel;
			}

			// recalculate cluster centers

			$difference = 0;
			for ($i = 0; $i < $this->settings['clustersNum']; $i++) {
				$oldCluster = $clusters[$i];
				$newCenter = $this->getCenter($pixelLists[$i]);
				$clusters[$i] = array($newCenter, $pixelLists[$i]);
				$distanceToOldClusterCenter = $this->distance($oldCluster[0], $newCenter);
				$difference = ($difference > $distanceToOldClusterCenter) ? $difference : $distanceToOldClusterCenter;
			}


			// sort clusters by size

			usort($clusters, function($a, $b) {
				$sizea = count($a[1]);
				$sizeb = count($b[1])
				if ($sizea == $sizeb) {
					return 0;
				}
				return ($sizea > $sizeb) ? -1 : 1;
			});

			$iteration++;
			if ($this->settings['verbose'])
				$this->verboseResult['iterations'][] = array(
					'clusterCenters' => $this->getColorList($clusters),
					'maxDistanceToPreviousIteration' => $difference
				);

			// if similarity within clusters goes below threshhold,
			// i.e. only little improvement between iterations,
			// stop and declare so far clusters as dominant colors

			if ($difference < $this->settings['similarity']) {
				break;
			}
		}


		// return the cluster centers

		$colors = array_map(function($i) {
			return $i[0];
		}, $clusters);


		return $colors;
	}



	// Euclidian distance formula
	// RGB colors are points in a three dimensional, Euclidian space

	private function distance($color1, $color2) {
		$distance = 0;

		for ($i = 0; $i < 3; $i++) {
			$distance += pow($color1[$i] - $color2[$i], 2);
		}

		$distance = sqrt($distance);
		return $distance;
	}



	// average all colors in a set for each color channel respectively

	private function getCenter($colors) {
		$n = count($colors);
		if ($n > 0) {
			$channels = array(0, 0, 0);

			for ($i = 0; $i < $n; $i++) {
				$channels[0] += $colors[$i][0];
				$channels[1] += $colors[$i][1];
				$channels[2] += $colors[$i][2];
			}
		
			$channels[0] = $channels[0] / $n;
			$channels[1] = $channels[1] / $n;
			$channels[2] = $channels[2] / $n;

			return $channels;
		}

		return array(0, 0, 0);
	}

	private function RGBtoHEX($rgb) {
		$hex = '#';
		foreach ($rgb as $dimension => $value) {
			$hex .= str_pad(dechex(floor($value)), 2, '0', STR_PAD_LEFT);
		}
		return $hex;
	}

	private function getColorList($clusters) {
		return array_map(function($item) {
			return $this->RGBtoHEX($item[0]);
		}, $clusters);
	}
}

/**
	* Within Wordpress a simple wrapper function may be used.
	* 
	* @param $attachment_id (int)  ID of attachment of which dominant colors to retrieve
	* @param $settings (array) 	   see above
	*
	* @return: see above
	*
	*
	* example: $colors = get_dominant_colors(350, array('colorsNum' => 2, 'clustersNum' => 4));
	*		   if (false !== $colors) {
	*		       foreach ($colors['foundColors']) {
	*		            ...
	*		       }
	*		   }
	*
**/

function get_dominant_colors($attachment_id = null, $settings = array()) {
	if (function_exists('wp_get_attachment_image_url')) {
		$dominantColors = false;
		if (null !== $attachment_id && is_int($attachment_id)) {
			$image = wp_get_attachment_image_src($attachment_id, 'thumbnail');
			$settings = (is_array($settings)) ? array_merge(array(), $settings) : array();
			$colors = new DominantColors($image[0], $settings);
			$dominantColors = $colors->getDominantColors();
		}

		return $dominantColors;
	} else {
		return "This function is a wrapper for the DominantColors class and only works within a Wordpress environment. See documentation for use outside of Wordpress.";
	}
}


?>
