# rdb-layout
A PHP script that reads RethinkDB files and visualizes their layout. Can be used to identify fragmentation in RethinkDB data files.

## Limitations
There are three important limitations at the moment:
* The code needs refactoring. It's currently a single gigantic file.
* The script only works correctly for files of up to 4 GB at the moment. For larger files it will silently report wrong results.
* There is almost no error checking.

## Output
<img src="/example.png">

The output of the script is a PNG file that visualizes the file layout. The metablock extent is grey, data extents are green, and LBA extents are blue. White is unused space in the file.
