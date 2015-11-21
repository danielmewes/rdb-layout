# rdb-layout
A PHP script that reads RethinkDB files and visualizes their layout. Can be used to identify fragmentation in RethinkDB data files.

## Limitations
There are three important limitations at the moment:
* The code needs refactoring. It's currently a single gigantic file.
* There is almost no error checking.
* Handling of files larger than 4 GB might or might not work, depending on your version of PHP and your platform.

## Output
<img src="/example.png">

The output of the script is a PNG file that corresponds to the file layout. The metablock extent is grey, data extents are green, and LBA extents are blue. White is unused space in the file.
For data extents (green), the script also visualizes the space utilization within the extents (total size of active blocks in the extent vs. garbage and unused space).
