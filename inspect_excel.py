import pandas as pd
import json

try:
    # Read the excel file
    xls = pd.ExcelFile('高齿生产日报表.xls')
    print("Sheet names:", xls.sheet_names)
    
    for sheet_name in xls.sheet_names:
        print(f"\n--- Sheet: {sheet_name} ---")
        df = pd.read_excel('高齿生产日报表.xls', sheet_name=sheet_name)
        print("Columns:", list(df.columns))
        print("Shape:", df.shape)
        # Display the first 30 rows
        pd.set_option('display.max_columns', 30)
        pd.set_option('display.max_rows', 50)
        pd.set_option('display.width', 1000)
        print(df.head(40))
        
except Exception as e:
    print("Error:", str(e))
