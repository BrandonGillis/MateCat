<?php

class Files_FileStruct extends DataAccess_AbstractDaoSilentStruct implements DataAccess_IDaoStruct {
    public $id  ;
    public $id_project  ;
    public $filename ;
    public $source_language ;
    public $mime_type ;
    public $sha1_original_file ;

    public function getSegmentsCount() {
        return ( new Segments_SegmentDao() )->countByFile( $this );
    }

    /**
     * @return Translations_SegmentTranslationStruct[]
     */
    public function getTranslations() {
        $dao = new Translations_SegmentTranslationDao() ;

        return $dao->getByFile( $this ) ;
    }

}
