import pymysql
import pandas as pd
import numpy as np
from datetime import date

df = pd.read_csv('./source-data/raw/TaiwanSpecies20221230_UTF8.csv')
df = df.replace({np.nan: ''})
df['scientific_name'] = df.apply(lambda x: x['name'] + ' ' + x.author, axis=1)
df['scientific_name'] = df.scientific_name.str.strip()
# df['taxon_rank'] = ''
# df['name_status'] = ''
df = df[['name_code','accepted_name_code','scientific_name','name_code','accepted_name_code','family','order','class','phylum','kingdom']]

# namecode
# accepted_namecode
# scientific_name (full name or canonical form is ok)
# name_url_id (the id which can be used to create a valid url to a taxon name page)
# accepted_name_url_id (the id which can be used to create a valid url to an accepted taxon name page, if the name is a synonym)
# family
# order
# class
# phylum
# kingdom



df.to_csv('./source-data/source_taicol_old_20221230.csv', header=None, index=False, sep='\t')


	#  * 0 namecode
	#  * 1 accepted_namecode
	#  * 2 scientific_name
	#  * 3 name_url_id
	#  * 4 accepted_url_id
	#  * 5 common_name_c
	#  * 6 taxon_rank
	#  * 7 genus
	#  * 8 family
	#  * 9 order
	#  * 10 class
	#  * 11 phylum
	#  * 12 kingdom
	#  * 13 simple_name
	#  * 14 name_status

# ------- generate from old TaiCOL ------- #


"""
Index(['kingdom', 'kingdom_c', 'phylum', 'phylum_c', 'class', 'class_c',
       'order', 'order_c', 'family', 'family_c', 'name_code', 'name', 'genus',
       'genus_c', 'species', 'infraspecies_marker', 'infraspecies',
       'infraspecies2_marker', 'infraspecies2', 'author', 'author2',
       'accepted_name_code', 'status_id', 'is_accepted_name', 'is_endemic',
       'is_marine', 'alien_status', 'is_fossil', 'common_name_c',
       'redlist_wang', 'redlist_wang_ass', 'redlist_chen', 'iucn_code',
       'iucn_assessment', 'iucn_dateassessed', 'iucn_id', 'coa_redlist_code',
       'cites_code', 'redlist2017', 'redlist2017_note', 'datelastmodified'],
      dtype='object')

     """


# infraspecies_marker
# infraspecies2_marker

# 'var.': 'variety'
# 'f.': 'form'
# 'subvar.': 'subvariety'
# 'subsp.':  'subspecies'
# 'fo.': 'form'
# 'f.sp.': 'special-form'
# 'cv.':  'cultivar'
# 'ab.':  'aberration'
# 'm.': 'morph'

rank_map = {'var.': 'variety', 'f.': 'form', 'subvar.': 'subvariety', 'subsp.':  'subspecies', 'fo.': 'form',
            'f.sp.': 'special-form', 'cv.':  'cultivar', 'ab.':  'aberration', 'm.': 'morph'}


# 'Human Virus'
# 'Plant Virus'
# 'Animal Virus'

df = pd.read_csv('/Users/taibif/Downloads/TaiwanSpecies20220415_UTF8.csv')

df = df.replace({np.nan: None})
df['simple_name'] = df['name']

# 僅有 種 & 種下

for i in df.index:
    if i % 1000 == 0:
        print(i)
    row = df.iloc[i]
    infra = row.infraspecies2_marker if row.infraspecies2_marker else row.infraspecies_marker
    if rank_map.get(infra):
        infra = rank_map.get(infra)
    df.loc[i, 'taxon_rank'] = infra if infra else 'species'
    author = row.author2 if row.author2 else row.author
    if author:
        df.loc[i, 'name'] += ' ' + author



df['common_name_c'] = df['common_name_c'].str.replace(';', ',')


"""
	/**
	 * 0 namecode
	 * 1 accepted_namecode
	 * 2 scientific_name
	 * 3 name_url_id
	 * 4 accepted_url_id
	 * 5 common_name_c
	 * 6 taxon_rank
	 * 7 genus
	 * 8 family
	 * 9 order
	 * 10 class
	 * 11 phylum
	 * 12 kingdom
	 * 13 simple_name
	 */

"""


df = df[['name_code', 'accepted_name_code', 'name', 'name_code', 'accepted_name_code',
         'common_name_c', 'taxon_rank', 'genus', 'family', 'order', 'class', 'phylum', 'kingdom', 'simple_name']]

today = date.today()

today_str = today.strftime("%Y%m%d")


df = df.replace({np.nan: None})

df.to_csv(f'./source-data/source_taicol_old_{today_str}.csv', sep='\t', header=None, index=False)

source = pd.read_table('./source-data/sources.csv', sep='\t', header=None)
# id 不可動
# name
source.loc[source[0]=='taicol',1] = 'TaiCOL' 
# url_base
source.loc[source[0]=='taicol',2] = 'https://taibnet.sinica.edu.tw/chi/taibnet_species_detail.php?name_code='
# citation
source.loc[source[0]=='taicol',3] = 'K. F. Chung, K. T. Shao, Catalogue of life in Taiwan. Web electronic publication. version 2022 https://taibnet.sinica.edu.tw'
# url
source.loc[source[0]=='taicol',4] = 'https://taibnet.sinica.edu.tw'
# version 下載檔案上的日期
source.loc[source[0]=='taicol',5] = '2022-12-30'

source.to_csv('./source-data/sources.csv', sep='\t', header=None, index=None)

