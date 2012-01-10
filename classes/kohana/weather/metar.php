<?php defined('SYSPATH') or die('No direct access allowed.');
/**
 * Parses METAR weather information for a given ICAO airport code. All METAR
 * data is collected from NOAA (National Oceanic and Atmospheric
 * Administration), http://www.noaa.gov/
 *
 * This class is not intended to convert it into a "human readable" sentence,
 * however it simply breaks the METAR down into an array whilst converting
 * units into something more usable (MPS to KT, inHg to mb for example).
 *
 * You should then take this array, or individual properties, and use it in any
 * way you wish (which could be for that "human readable" sentence).
 *
 * - All pressures are in millibars
 * - All speeds are in knots
 * - All temperatures are in Celsius
 *
 * @author     Alex Cartwright <alexc223@gmail.com>
 * @copyright  Copyright (c) 2012, Alex Cartwright
 * @license    BSD 3-Clause License, see LICENSE file
 */
class Kohana_Weather_METAR {

	/**
	 * Raw METAR data
	 * @var string
	 */
	protected $_raw;

	/**
	 * Parsed METAR data
	 * @var array
	 */
	protected $_parsed = array();

	/**
	 * Takes either a 4 char ICAO airport code which will then get the raw
	 * METAR data from NOAA, or the provided string will be used as the METAR.
	 *
	 * @param   string  $code
	 * @return  void
	 */
	public function __construct($code)
	{
		if (strlen($code) === 4)
		{
			$data = file(
				'http://weather.noaa.gov/pub/data/observations/metar/stations/'.$code.'.TXT',
				FILE_IGNORE_NEW_LINES
			);

			if (empty($data[0]) OR empty($data[1]))
				throw new Kohana_Exception('raw METAR data returned is malformed for ":icao"',
					array('icao' => $code));

			$this->_raw = $data[1];
		}
		else
		{
			// Treat the input as the METAR data
			$this->_raw = $code;
		}
	}

	/**
	 * Simple getter for the parsed METAR data. See the array keys returned
	 * from Weather_METAR::as_array() for the available properties you can
	 * get.
	 *
	 * @param   string  $name
	 * @return  mixed
	 */
	public function __get($name)
	{
		if ( ! isset($this->_parsed[$name]))
		{
			$method = '_parse_'.$name;
			if (method_exists($this, $method))
			{
				$this->$method();
			}
			else
			{
				throw new Kohana_Exception('The :property property does not exist in the :class class',
					array(':property' => $name, ':class' => get_class($this)));
			}
		}

		return $this->_parsed[$name];
	}

	/**
	 * Returns an array of the parsed METAR data
	 *
	 * @return  array
	 */
	public function as_array()
	{
		return array(
			'altimeter'     => $this->altimeter,
			'cavok'         => $this->cavok,
			'cloud'         => $this->cloud,
			'cloud_ceiling' => $this->cloud_ceiling,
			'dewpoint'      => $this->dewpoint,
			'icao_code'     => $this->icao_code,
			'temperature'   => $this->temperature,
			'time'          => $this->time,
			'visibility'    => $this->visibility,
			'wind'          => $this->wind,
		);
	}

	/**
	 * Parse the altimeter (QNH)
	 *
	 * @return  void
	 */
	protected function _parse_altimeter()
	{
		preg_match('#\s(A|Q)([0-9]{4})#', $this->_raw, $matches);

		if (empty($matches[1]) OR empty($matches[2]))
		{
			$this->_parsed['altimeter'] = FALSE;
		}
		else
		{
			if ('A' === $matches[1])
			{
				// Convert inches of mercury (inHg) to millibars (mb)
				$this->_parsed['altimeter'] = round($matches[2] * 33.86);
			}
			else
			{
				$this->_parsed['altimeter'] = (int) $matches[2];
			}
		}
	}

	/**
	 * Parse CAVOK
	 *
	 * @return  void
	 */
	protected function _parse_cavok()
	{
		$this->_parsed['cavok'] = strpos($this->_raw, 'CAVOK') !== FALSE;
	}

	/**
	 * Parse the cloud information & calculate the cloud ceiling
	 *
	 * @return  void
	 */
	protected function _parse_cloud()
	{
		preg_match_all(
			'#\s
			(?P<coverage>(?:FEW|SCT|BKN|OVC))
			(?P<height>[0-9]{3})
			(?P<type>(?:CU|CB|TCU|CI))?
			#x',
			$this->_raw,
			$matches
		);

		if (empty($matches['coverage']))
		{
			if (strpos($this->_raw, ' NSC') !== FALSE)
			{
				$this->_parsed['cloud'] = 'no significant cloud';
			}
			elseif (strpos($this->_raw, ' NCD') !== FALSE)
			{
				$this->_parsed['cloud'] = 'no cloud detected';
			}
			else
			{
				$this->_parsed['cloud'] = FALSE;
			}

			$this->_parsed['cloud_ceiling'] = FALSE;
		}
		else
		{
			$ceiling = FALSE;

			foreach ($matches['coverage'] as $key=>$coverage)
			{
				$height = $matches['height'][$key] * 100;

				if (('BKN' === $coverage OR 'OVC' === $coverage) AND (FALSE === $ceiling OR $height < $ceiling))
				{
					$ceiling = $height;
				}

				switch ($coverage)
				{
					case 'FEW':
						$coverage_human = 'few';
						break;
					case 'SCT':
						$coverage_human = 'scattered';
						break;
					case 'BKN':
						$coverage_human = 'broken';
						break;
					case 'OVC':
						$coverage_human = 'overcast';
						break;
				}

				$this->_parsed['cloud'][] = array(
					'coverage' => $coverage_human,
					'height'   => $height,
					'type'     => empty($matches['type'][$key]) ? FALSE : $matches['type'][$key],
				);
			}

			$this->_parsed['cloud_ceiling'] = $ceiling;
		}
	}

	/**
	 * Parse the cloud information & calculate the cloud ceiling
	 *
	 * @return  void
	 */
	protected function _parse_cloud_ceiling()
	{
		$this->_parse_cloud();
	}

	/**
	 * Parse the dewpoint and temperature in one
	 *
	 * @return  void
	 */
	protected function _parse_dewpoint()
	{
		$this->_parse_temperature();
	}

	/**
	 * Parse the ICAO airport code
	 *
	 * @return  void
	 */
	protected function _parse_icao_code()
	{
		// Assume the first 4 chars are the ICAO code
		$this->_parsed['icao_code'] = substr($this->_raw, 0, 4);
	}

	/**
	 * Parse the dewpoint and temperature in one
	 *
	 * @return  void
	 */
	protected function _parse_temperature()
	{
		preg_match('#\s(?P<temp>M?[0-9]{2})/(?P<dew>M?[0-9]{2})#', $this->_raw, $matches);

		if (empty($matches['temp']) OR empty($matches['dew']))
		{
			$this->_parsed['temperature'] = FALSE;
			$this->_parsed['dewpoint'] = FALSE;
		}
		else
		{
			$this->_parsed['temperature'] = (int) str_replace('M', '-', $matches['temp']);
			$this->_parsed['dewpoint'] = (int) str_replace('M', '-', $matches['dew']);
		}
	}

	/**
	 * Parse the observation time
	 *
	 * @return  void
	 */
	protected function _parse_time()
	{
		preg_match('#\s([0-3][0-9][0-2][0-9][0-5][0-9])Z#', $this->_raw, $matches);

		if (empty($matches[1]))
		{
			$this->_parsed['time'] = FALSE;
		}
		else
		{
			$date = DateTime::createFromFormat('dGie', $matches[1].'UTC');
			if ($date instanceof DateTime)
			{
				$this->_parsed['time'] = $date->getTimestamp();
			}
			else
			{
				$this->_parsed['time'] = FALSE;
			}
		}
	}

	/**
	 * Parse the prevailing visibility
	 *
	 * @return  void
	 */
	protected function _parse_visibility()
	{
		preg_match('#\s([0-9]{4})\s#', $this->_raw, $matches);

		if (empty($matches[1]))
		{
			$this->_parsed['visibility'] = FALSE;
		}
		else
		{
			$this->_parsed['visibility'] = (int) $matches[1];
		}
	}

	/**
	 * Parse the wind speed, direction & any variation
	 *
	 * @return  void
	 */
	protected function _parse_wind()
	{
		preg_match(
			'#\s
			(?P<direction>[0-9]{3})
			(?P<speed>[0-9]{2,3})
			(?:G(?P<gusting>[0-9]{2,3}))?
			(?P<measurement>(?:KT|MPS))
			#x',
			$this->_raw,
			$matches
		);

		if (empty($matches['direction']) OR empty($matches['speed']))
		{
			$this->_parsed['wind'] = FALSE;
		}
		else
		{
			if ('MPS' === $matches['measurement'])
			{
				// Convert metres per second into knots
				$speed = round($matches['speed'] * 0.868976242);
			}
			else
			{
				$speed = (int) $matches['speed'];
			}

			$this->_parsed['wind'] = array(
				'direction' => (int) $matches['direction'],
				'speed'     => $speed,
				'gusting'   => isset($matches['gusting']) ? (int) $matches['gusting'] : FALSE
			);

			// Check for wind variation
			preg_match('#\s([0-9]{3}V[0-9]{3})#', $this->_raw, $matches);

			if (empty($matches[1]))
			{
				$this->_parsed['wind']['variation'] = FALSE;
			}
			else
			{
				$this->_parsed['wind']['variation'] = array_map('intval', explode('V', $matches[1]));
			}
		}
	}

}
