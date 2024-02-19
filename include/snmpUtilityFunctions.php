<?php




// Return storage type from oid 1.3.6.1.2.1.25.2.1
function snmpHrStorageType( $intFromOid ) {
  switch ($intFromOid) {
    case "1":
      return "hrStorageOther";
    case "2":
      return "hrStorageRam";
    case "3":
      return "hrStorageVirtualMemory";
    case "4":
      return "hrStorageFixedDisk";
    case "5":
      return "hrStorageRemovableDisk";
    case "6":
      return "hrStorageFloppyDisk";
    case "7":
      return "hrStorageCompactDisc";
    case "8":
      return "hrStorageRamDisk";
    case "9":
      return "hrStorageFlashMemory";
    case "10":
      return "hrStorageNetworkDisk";
  }
}

?>
