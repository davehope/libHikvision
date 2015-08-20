<?php
/*
 * Hikvision CCTV Class, version 1.0
 * This class will parse a Hikvision index file (e.g. index00.bin) tha
 * typically gets stored on external media such as an SD card or NFS share.
 *
 * Access to ffmpeg and shell() is required for the creation of thumbnails.
 *
 * Thanks go to Alexey Ozerov for his C++ hiktools utility:
 *    https://github.com/aloz77/hiktools
 *
 * 
 */ 
error_reporting(E_ALL | E_STRICT);
ini_set('display_errors', 1);

define("HEADER_LEN", 1280);	// Length of the header struct in bytes.
define("FILE_LEN", 32);		// Length of the file struct in bytes.
define("SEGMENT_LEN", 80);	// Length of the segment struct in bytes.

class hikvisionCCTV
{
	private $indexFile;
	private $path;

	///
	/// __construct( Path to data directory )
	/// Created a new instance of this class. The path MUST end in a '/'.
	///
	public function __construct( $_path )
	{
		$this->path = $_path;
		$this->indexFile = $this->pathJoin($_path ,'index00.bin');
	}


	///
	/// getFileHeader()
	/// Return array containing the file header from Hikvision "index00.bin".
	/// Based on the work of Alex Ozerov. (https://github.com/aloz77/hiktools/)
	///
	public function getFileHeader()
	{
		$fh = fopen($this->indexFile, 'rb');

		// Read length of file header.
		$data = fread($fh, HEADER_LEN);
		$tmp = unpack(
			'Q1modifyTimes/'.
			'I1version/'.
			'I1avFiles/'.
			'I1nextFileRecNo/'.
			'I1lastFileRecNo/'.
			'C1176curFileRec/'.
			'C76unknown/'.
			'I1checksum', $data);
		fclose($fh);
		return $tmp;
	}


	///
	/// getFiles()
	/// Return list of files. One video file may contain multiple segments,
	/// i.e. multiple events - motion detection, etc.
	/// Currently unused as it's more useful to return segments. 
	/// Based on the work of Alex Ozerov. (https://github.com/aloz77/hiktools/)
	///
	public function getFiles()
	{
		$results = array();
		$header = $this->getFileHeader();
		$fh = fopen($this->indexFile, 'rb');

		// Seek to end of header.
		fread($fh, HEADER_LEN);

		// Iterate over recordings.
		for($i=0; $i<$header['avFiles']; $i++)
		{
			// Read length of recoridng header.
			$data = fread($fh, FILE_LEN);
			if( $data === false )
				break;	
	
			// Unpack data from the file based on C data types.
			$tmp = unpack(
				'I1fileNo/'.
				'S1chan/'.
				'S1segRecNums/'.
				'I1startTime/'. // time_t. Hikvision is x86 and uses a 4 Byte long.
				'I1endTime/'. // time_t - Hikvision is x86 and uses a 5 Byte long.
				'C1status/'. 
				'C1unknownA/'.
				'S1lockedSegNum/'.
				'C4unknownB/'.
				'C8infoTypes/'
				,$data);

			if( $tmp['chan'] != 65535 )
				array_push($results, $tmp);
		}
		fclose($fh);
		return $results;
	}


	///
	/// getSegments()
	/// Returns an array of files and segments from a Hikvision "index00.bin"
	/// file.
	/// Based on the work of Alex Ozerov. (https://github.com/aloz77/hiktools/)
	///
	public function getSegments()
	{
		// Maximum number of segments possible per recording.
		$maxSegments = 256;	

		$results = array();
		$fh = fopen($this->indexFile, 'rb');

		// Seek to the end of the header and recordings.
		$header = $this->getFileHeader();
		$offset = HEADER_LEN + ($header['avFiles'] * FILE_LEN);
		fread($fh, $offset);

		// Iterate over the number of recordings we have.
		for($i=0;$i<$header['avFiles'];$i++)
		{
			$results[$i] = array();
			for ($j=0;$j<$maxSegments;$j++)
			{
				// Read length of the segment header.
				$data = fread($fh, SEGMENT_LEN);
				if($data === false)
					break;

				$tmp = unpack(
					'C1type/'.
					'C1status/'.
					'C2resA/'.
					'C4resolution/'.
					'P1startTime/'. // unit64_t
					'P1endTime/'. // uint64_t
					'P1firstKeyFrame_absTime/'. // unit64_t
					'I1firstKeyFrame_stdTime/'.
					'I1lastFrame_stdTime/'.
					'IstartOffset/'.
					'IendOffset/'.
					'C4resB/'.
					'C4infoNum/'.
					'C8infoTypes/'.
					'C4infoStartTime/'.
					'C4infoEndTime/'.
					'C4infoStartOffset/'.
					'C4infoEndOffset'
					,$data);
		
				// Ignore empty and those which are still recording.	
				if($tmp['type'] != 0 && $tmp['endTime'] != 0)
					array_push($results[$i], $tmp);
			}
		}
		fclose($fh);
		return $results;
	}
	
	
	///
	/// getSegmentsBetweenDates( Start Date , End Date)
	/// Returns an array of segments between the specified dates.
	///
	public function getSegmentsBetweenDates($_start , $_end)
	{
		$results = array();
		
		// Iterate over all files.
		$current_file = 0;
		foreach ($this->getSegments() as $file)
		{
			// Iterate over segments associated with this recording.
			foreach($file as $segment)
			{
				$startTime = $this->convertTimestampTo32($segment['startTime']);
				$endTime = $this->convertTimestampTo32($segment['endTime']);
				$segment['cust_fileNo'] = $current_file;
				$segment['cust_startTime'] = $startTime;
				$segment['cust_endTime'] = $endTime;
				// Check if the segment began recording in the specified window
				if( $_start < $startTime && $_end > $endTime )
					array_push($results, $segment);
			}
			$current_file++;
		}
		
		return $results;
	}
	
	
	///
	/// getSegmentsByDate( Start Date , End Date)
	/// Returns an array of segments between the speficied dates, indexed by 
	/// day (unix timestamp)
	///
	public function getSegmentsByDate($_start, $_end)
	{
		$segments = $this->getSegmentsBetweenDates($_start, $_end);

		// Iterate over the list of segments and index them by day.
		$segmentsByDay = array();
		foreach($segments as $segment)
		{
			$startTime = $segment['cust_startTime'];
			$index = strtotime("midnight", $startTime);
			
			// This day doesn't exist, add it to our list.
			if(!isset( $segmentsByDay[$index] ))
			{
				$segmentsByDay[$index] = array(
					'start' => $index,
					'end' => strtotime("tomorrow", $startTime) - 1,
					'segments' => array()
					);
			}
			// Add segment to day.
			$segmentsByDay[$index]['segments'][] = $segment;
		}
		
		return $segmentsByDay;
	}
	
	
	///
	/// timeFilename( Prefix, Suffix, Start Time, End Time)
	/// Generates a file name based on the speificed values. Used to generate an
	// output file name for video clips.
	///
	public function timeFilename($_prefix, $_suffix, $_startTime, $_endTime)
	{
		$startTime = strftime("%Y-%m-%d_%H.%M.%S",$_startTime);
		$endTime = strftime("%H.%M.%S", $_endTime);

		return $_prefix."_".$startTime."_to_".$endTime.$_suffix;
	}
	
	
	//
	// convertTimestampTo32( 64bit timestamp )
	// Converts an unsigned long long (uint_64) to an unsigned long. Useful
	// since PHP's 64bit timestamp support is useless.
	// 
	public function convertTimestampTo32( $_in )
	{
		$mask = 0x00000000ffffffff; 
		return $_in & $mask;
	}
	
	
	///
	/// getSegmentClipHTTP( File Number , Start Offset, End Offset )
	/// Extracts a recording segment from the specified file, chunking the
	/// request to 4kb at a time to conserve memory.
	///
	public function getSegmentClipHTTP( $_file , $_startOffset, $_endOffset )
	{
		$file = $this->getFileName($_file);
		$path = $this->pathJoin($this->path , $file);
		
		$fh = fopen( $path, 'rb');
		if($fh == false)
			die("Unable to open $path");
		
		if( fseek($fh, $_startOffset) === false )
			die("Unable to seek to position $_startOffset in $path");
		
		header('Content-Disposition: attachment; filename="'.$file.'"');
		
		if (ob_get_level() == 0)
			ob_start();
		
		while(ftell($fh) < $_endOffset)
		{
			print fread($fh, 4096);
		}
		ob_end_flush();
		fclose($fh);
	}
	
	
	///
	/// getFileName( File Number )
	/// Returns the full path to the specified recording file.
	///
	public function getFileName( $_file )
	{
		$file = sprintf('hiv%05u.mp4', $_file);
		return $file;
	}
	
	
	///
	/// extractThumbnail(File Number, offset, Path to output file)
	/// Extracts a thumbnail from a recording file based on the offset provided
	///
	public function extractThumbnail($_file, $_offset, $_output)
	{
		$path = $this->pathJoin($this->path , $this->getFileName($_file));
		
		if(!file_exists($_output))
		{
			$cmd = 'dd if='.$path.' skip='.$_offset.' ibs=1 | ffmpeg -i pipe:0 -vframes 1 -an '.$_output.' >/dev/null 2>&1';
			system($cmd);
		}
	}
	
	
	///
	/// pathJoin (paths)
	/// Joins two or more strings together to produce a valid file path.
	///
	private function pathJoin()
	{
		return preg_replace('~[/\\\]+~', DIRECTORY_SEPARATOR, implode(DIRECTORY_SEPARATOR, func_get_args()));
	}
	
}
?>
